<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;

/**
 * Persists and compares duplication baselines for CI gate workflows.
 *
 * A baseline captures the current set of clusters as a content-hash
 * fingerprint so that subsequent runs can detect NEW duplication that
 * was not present when the baseline was recorded.
 *
 * Baseline format:
 * {
 *   "version": 1,
 *   "clusters": [
 *     {
 *       "id": "cluster-abc123",
 *       "impact": 150,
 *       "member_hashes": ["sha256:abc...", "sha256:def..."]
 *     }
 *   ]
 * }
 *
 * member_hashes are sorted SHA-256 fingerprints of each block's
 * content identity: sha256($block->file . $block->range->start . $block->range->end).
 * They are NOT dependent on cluster IDs which may change between runs.
 */
final class BaselineStore
{
    private const CURRENT_VERSION = 1;

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
     * @param list<array{id: string, impact: int, member_hashes: list<string>}> $currentEntries
     * @param list<array{id: string, impact: int, member_hashes: list<string>}> $baselineEntries
     * @return list<array{id: string, impact: int, member_hashes: list<string>}>
     */
    public function compareBaselines(array $currentEntries, array $baselineEntries): array
    {
        if ($baselineEntries === []) {
            return $currentEntries;
        }

        // Build a map of baseline cluster member hashes for fast lookup
        // Key: sorted hash string; Value: list of hash sets from baseline clusters
        $baselineHashSets = [];
        foreach ($baselineEntries as $baseline) {
            $hashes = $baseline['member_hashes'];
            sort($hashes);
            $baselineHashSets[] = $hashes;
        }

        $newClusters = [];
        foreach ($currentEntries as $current) {
            $currentHashes = $current['member_hashes'];
            sort($currentHashes);

            // Check if current cluster is "covered" by any baseline cluster
            // A cluster is covered if ALL its member hashes appear in some baseline cluster
            $covered = false;
            foreach ($baselineHashSets as $baselineHashes) {
                // Current is covered if all its hashes are present in baseline hashes
                if ($this->isSubset($currentHashes, $baselineHashes)) {
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
     * Compute a SHA-256 content hash for a block.
     * Uses file path + start line + end line as the content identity.
     */
    public function computeBlockHash(Block $block): string
    {
        $content = $block->file . $block->range->start . $block->range->end;
        return 'sha256:' . hash('sha256', $content);
    }

    /**
     * Check if $needle is a subset of $haystack (all elements present).
     *
     * @param list<string> $needle
     * @param list<string> $haystack
     */
    private function isSubset(array $needle, array $haystack): bool
    {
        if (count($needle) > count($haystack)) {
            return false;
        }
        $haystackSet = array_flip($haystack);
        foreach ($needle as $item) {
            if (!isset($haystackSet[$item])) {
                return false;
            }
        }
        return true;
    }
}
