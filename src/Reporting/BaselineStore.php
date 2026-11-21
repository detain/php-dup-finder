<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Util\PathCanonical;

/**
 * Persists and compares duplication baselines for CI gate workflows.
 *
 * A baseline captures the current set of clusters as a content-hash
 * fingerprint so that subsequent runs can detect NEW duplication that
 * was not present when the baseline was recorded.
 *
 * Baseline format (the live shape at CURRENT_VERSION = 3):
 * {
 *   "version": 3,
 *   "clusters": [
 *     {
 *       "id": "F77b1cccd981b8db5",
 *       "impact": 150,
 *       "member_hashes": ["sha256:abc...", "sha256:def..."]
 *     }
 *   ]
 * }
 *
 * member_hashes are sorted SHA-256 fingerprints of each block's
 * content identity: sha256($block->file . '|' . $block->structuralHash).
 * Item 4.4 (ruling §5e) replaced v1's positional formula
 * (file . start . end), which churned every baseline entry whenever an
 * unrelated edit shifted lines above a block. They are NOT dependent on
 * cluster IDs nor on where a block sits in its file.
 */
final class BaselineStore
{
    // Baseline v2 (item 4.4): member_hashes are content-derived. v1 files
    // keyed positions, so the same accepted cluster could silently miss
    // under a shifted listing — readBaseline() refuses them loudly and
    // the operator re-accepts once, never silently re-baselined.
    // v3 (phpver-fix): member_hashes canonicalize the member path under
    // the configured scan roots, and finding ids moved with the recipe;
    // v2 files (absolute paths / old ids) mismatch on any other machine,
    // so they fail the same loud refusal — re-accept once.
    private const CURRENT_VERSION = 3;

    /**
     * @param list<string> $scanRoots configured scan roots, threaded from
     *                          Command so keys match the runner-agnostic
     *                      FindingId recipe (golden/baseline portability).
     */
    public function __construct(private readonly array $scanRoots = [])
    {
    }

    /**
     * Write a baseline snapshot of the current clusters to a JSON file.
     * Overwrites $path if it exists.
     *
     * @param list<Cluster> $clusters
     */
    public function writeBaseline(array $clusters, string $path): void
    {
        $entries = [];
        foreach ($clusters as $cluster) {
            $memberHashes = $this->computeMemberHashes($cluster);
            sort($memberHashes);
            $entries[] = [
                'id' => $cluster->id,
                'impact' => $cluster->impact,
                'member_hashes' => $memberHashes,
            ];
        }

        $payload = [
            'version' => self::CURRENT_VERSION,
            'clusters' => $entries,
        ];

        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode baseline JSON: ' . json_last_error_msg());
        }

        if (@file_put_contents($path, $json) === false) {
            throw new \RuntimeException("Failed to write baseline file: $path");
        }
    }

    /**
     * Read a baseline snapshot from a JSON file.
     *
     * @return list<array{id: string, impact: int, member_hashes: list<string>}>
     */
    public function readBaseline(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Baseline file not found: $path");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read baseline file: $path");
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Baseline file is not valid JSON: $path");
        }

        if (!isset($decoded['version'], $decoded['clusters']) || !is_int($decoded['version'])) {
            throw new \RuntimeException("Baseline file has invalid structure: $path");
        }

        if ($decoded['version'] !== self::CURRENT_VERSION) {
            throw new \RuntimeException(
                "Baseline version mismatch: expected " . self::CURRENT_VERSION
                . ", got {$decoded['version']} in $path"
            );
        }

        $clusters = $decoded['clusters'];
        if (!is_array($clusters)) {
            throw new \RuntimeException("Baseline 'clusters' must be an array: $path");
        }

        $result = [];
        foreach ($clusters as $entry) {
            if (!is_array($entry) || !isset($entry['id'], $entry['impact'], $entry['member_hashes'])) {
                throw new \RuntimeException("Baseline entry missing required fields: $path");
            }
            if (!is_int($entry['impact']) || $entry['impact'] < 0) {
                throw new \RuntimeException("Baseline entry 'impact' must be a non-negative integer: $path");
            }
            if (!is_array($entry['member_hashes'])) {
                throw new \RuntimeException("Baseline entry 'member_hashes' must be an array: $path");
            }
            foreach ($entry['member_hashes'] as $hash) {
                if (!is_string($hash) || $hash === '') {
                    throw new \RuntimeException("Baseline entry 'member_hashes' must contain non-empty strings: $path");
                }
            }
            // Cast explicitly to satisfy Psalm type inference
            /** @var array{id: string, impact: int, member_hashes: list<string>} $typedEntry */
            $typedEntry = [
                'id' => (string)$entry['id'],
                'impact' => (int)$entry['impact'],
                'member_hashes' => array_values(array_filter(
                    array_map('strval', $entry['member_hashes']),
                    static fn(string $h): bool => $h !== '',
                )),
            ];
            $result[] = $typedEntry;
        }

        return $result;
    }

    /**
     * Compare current clusters against a baseline and return clusters
     * that are NEW (not present in the baseline).
     *
     * A current cluster is considered "new" if its member hashes are NOT
     * a subset of any baseline cluster's member hashes. This allows
     * baseline clusters to grow (new members added) without triggering
     * a new-duplicate gate.
     *
     * Each baseline entry's hash list is flipped into its lookup map
     * exactly ONCE, before the comparison loop (output-029). The
     * previous shape rebuilt the flip inside isSubset() on every
     * current × baseline candidate pair — up to 10⁸ constructions on
     * a 10k × 10k CI-gate corpus.
     *
     * @param list<array{id: string, impact: int, member_hashes: list<string>}> $currentEntries
     * @param list<array{id: string, impact: int, member_hashes: list<string>}> $baselineEntries
     * @return list<array{id: string, impact: int, member_hashes: list<string>}>
     */
    public function compareBaselines(array $currentEntries, array $baselineEntries): array
    {
        if ($baselineEntries === []) {
            return $currentEntries;
        }

        // One sort + one flip per baseline entry, hoisted out of the
        // per-pair loop. 'count' preserves the RAW (duplicate-carrying)
        // member count used by isSubset()'s size guard: array_flip
        // collapses duplicates, so the flipped set's own size would
        // change verdicts on hash lists that carry them.
        /** @var list<array{count: int, lookup: array<array-key, int>}> $baselineSets */
        $baselineSets = [];
        foreach ($baselineEntries as $baseline) {
            $hashes = $baseline['member_hashes'];
            sort($hashes);
            $baselineSets[] = [
                'count' => count($hashes),
                'lookup' => array_flip($hashes),
            ];
        }

        $newClusters = [];
        foreach ($currentEntries as $current) {
            $currentHashes = $current['member_hashes'];
            sort($currentHashes);

            // Check if current cluster is "covered" by any baseline cluster
            // A cluster is covered if ALL its member hashes appear in some baseline cluster
            $covered = false;
            foreach ($baselineSets as $baselineSet) {
                // Current is covered if all its hashes are present in baseline hashes
                if ($this->isSubset($currentHashes, $baselineSet['count'], $baselineSet['lookup'])) {
                    $covered = true;
                    break;
                }
            }

            if (!$covered) {
                $newClusters[] = $current;
            }
        }

        return $newClusters;
    }

    /**
     * Compute sorted member hashes for a cluster.
     *
     * @param Cluster $cluster
     * @return list<string>
     */
    private function computeMemberHashes(Cluster $cluster): array
    {
        $hashes = [];
        foreach ($cluster->members as $block) {
            $hashes[] = $this->computeBlockHash($block);
        }
        sort($hashes);
        return $hashes;
    }

    /**
     * Compute a SHA-256 content hash for a block: file path + the
     * normalized structural fingerprint. Content-keyed on purpose
     * (item 4.4 / §5e) — line shifts elsewhere in the file must not
     * move the hash, while a real body edit must.
     */
    public function computeBlockHash(Block $block): string
    {
        $content = PathCanonical::underRoots($block->file, $this->scanRoots) . '|' . $block->structuralHash;
        return 'sha256:' . hash('sha256', $content);
    }

    /**
     * Check if $needle is a subset of a pre-flipped baseline hash set
     * (all elements present). Pure isset-lookups — the flip happened
     * once per baseline entry in compareBaselines(), never per pair.
     *
     * @param list<string> $needle
     * @param int $haystackCount raw member count of the baseline set
     * @param array<array-key, int> $haystackLookup array_flip of the baseline hashes
     */
    private function isSubset(array $needle, int $haystackCount, array $haystackLookup): bool
    {
        if (count($needle) > $haystackCount) {
            return false;
        }
        foreach ($needle as $item) {
            if (!isset($haystackLookup[$item])) {
                return false;
            }
        }
        return true;
    }
}
