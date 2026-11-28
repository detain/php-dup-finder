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
 * content identity: sha256(canonicalFile . '|' . $block->structuralHash)
 * with canonicalFile the PathCanonical root-stripped form (see below).
 * Item 4.4 (ruling §5e) replaced v1's positional formula
 * (file . start . end), which churned every baseline entry whenever an
 * unrelated edit shifted lines above a block. They are NOT dependent on
 * cluster IDs nor on where a block sits in its file.
 *
 * Cross-run portability (once-044, note to S50): computeBlockHash
 * shares the FindingId PathCanonical recipe exactly, so the Windows
 * separator-fold reaches baselines too — a baseline recorded on one
 * machine compares on another even when the scan root is spelled in a
 * natively different join form (C:\proj\… vs. /proj/…). Slash-form
 * scans hash byte-identically, so no existing v3 baseline re-hashes.
 *
 * Baseline v4 (item 4.4 APPLY half) adds an optional top-level
 * "dispositions" map carrying the team's DECISIONS per stable finding
 * id — see BaselineDisposition for the record shape and semantics:
 * {
 *   "version": 4,
 *   "clusters": [ ...same entries as v3... ],
 *   "dispositions": {
 *     "F77b1cccd981b8db5": {
 *       "status": "accepted",
 *       "note": "intentional protocol mirrors",
 *       "accepted_at": "2026-11-28T12:00:00+00:00",
 *       "provenance": {
 *         "files": ["src/A.php"], "mtimes": {"src/A.php": 1764316800},
 *         "git_sha": "abc123...", "member_hashes": ["sha256:..."]
 *       }
 *     }
 *   }
 * }
 * Reads accept [3, 4]: a v3 file is a complete gate baseline with an
 * empty decision set. Writes stay v3 while no dispositions exist, so
 * byte-frozen v3 consumers (and the Golden corpus) never observe the
 * new key until a decision is actually recorded.
 *
 * Write posture (once-056, step once-S50): writeBaseline() replaces the
 * file atomically — exclusive temp in the target directory, LOCK_EX
 * while its bytes land, fsync before the rename, short-write refusal
 * after close. A reader (or a second CI writer) never observes a
 * truncated baseline, and a failed write leaves the previous file
 * intact with no temp residue.
 *
 * Read posture (once-055, step once-S50): an empty, whitespace-only or
 * bare `[]`/`{}` baseline file is INVALID INPUT, not a runtime death —
 * the refusal names the repair (delete → first-run auto-baseline, or
 * --baseline-out to re-accept) and carries exception code 2, which the
 * console surface maps to the CLI's exit-2 invalid-invocation door.
 * Well-formed-but-incompatible documents (old versions, missing keys)
 * keep their loud structural refusals unchanged.
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
    // v4 (item 4.4 APPLY half): optional top-level "dispositions" map of
    // accepted/unresolved decisions (BaselineDisposition). Reads accept
    // [3, 4] — a v3 file is a valid baseline with no decisions. Writes
    // emit v3 whenever the decision set is empty, so the v3 on-disk
    // shape stays byte-identical for consumers that never opt in.
    private const WRITE_VERSION = 4;
    /** @var list<int> versions readBaseline()/readDispositions() accept */
    private const SUPPORTED_VERSIONS = [3, 4];

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
     * Replaces $path if it exists — atomically, never in place: the
     * bytes land in an exclusive temp that is flocked, fsynced and only
     * then renamed over the target (once-056), so a concurrent reader
     * sees either the old complete file or the new complete file.
     *
     * @param list<Cluster> $clusters
     * @param array<string, BaselineDisposition> $dispositions decisions to
     *                          persist alongside the gate entries; empty
     *                          keeps the byte-identical v3 document shape.
     */
    public function writeBaseline(array $clusters, string $path, array $dispositions = []): void
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
            'version' => $dispositions === [] ? 3 : self::WRITE_VERSION,
            'clusters' => $entries,
        ];

        if ($dispositions !== []) {
            $records = [];
            foreach ($dispositions as $findingId => $disposition) {
                $records[$findingId] = $disposition->toArray();
            }
            $payload['dispositions'] = $records;
        }

        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode baseline JSON: ' . json_last_error_msg());
        }

        $this->writeAtomically($json, $path);
    }

    /**
     * once-056: the write itself, one complete payload or no change.
     *
     * The old shape was a single `file_put_contents($path, ...)` whose
     * `=== false` guard missed short writes (the call returns the byte
     * COUNT on partial success) and whose in-place truncate left every
     * later run holding a wrecked gate input. The replacement claims a
     * uniquely named temp in the TARGET directory (`x`: exclusive, so a
     * pre-claimed name is never clobbered), holds LOCK_EX while the
     * bytes stream in, fsyncs before the rename so a power loss cannot
     * resurrect an empty shell under the final name, and re-counts the
     * landed file before handing it over — no consumer can race a
     * `.tmp` name, unlike the final one (the posture
     * Server/AnalysisWorker.php uses for result files). A failure in
     * any arm discards the temp and leaves the previous baseline
     * standing. The flock serializes temp handling belt-and-braces:
     * rename() atomicity alone already guarantees whole-file readers.
     */
    private function writeAtomically(string $json, string $path): void
    {
        $tmp = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path)
            . '.' . getmypid() . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $handle = @fopen($tmp, 'x');
        if ($handle === false) {
            throw new \RuntimeException("Failed to create temp baseline file: $tmp");
        }

        if (@flock($handle, LOCK_EX) === false) {
            fclose($handle);
            @unlink($tmp);
            throw new \RuntimeException("Failed to lock temp baseline file: $tmp");
        }

        $written = fwrite($handle, $json);
        $flushed = fflush($handle);
        $synced = $flushed && fsync($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        $expected = strlen($json);
        if (!$flushed || !$synced || $written !== $expected) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf(
                'Failed to write baseline file %s: temp stream took %d of %d bytes%s',
                $path,
                $written === false ? 0 : $written,
                $expected,
                $synced ? '' : ' and fsync failed (the bytes never reached stable storage)',
            ));
        }

        $landed = @filesize($tmp);
        if ($landed !== $expected) {
            // The write count is not the truth until the closed file
            // agrees: a full-count return can still sit on a stream that
            // lost bytes at close.
            @unlink($tmp);
            throw new \RuntimeException(sprintf(
                'Failed to write baseline file %s: temp landed %d of %d bytes',
                $path,
                $landed === false ? 0 : $landed,
                $expected,
            ));
        }

        if (@rename($tmp, $path) === false) {
            @unlink($tmp);
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

        // once-055: an empty document — zero bytes, whitespace, or a
        // well-formed `[]`/`{}` that carries no baseline — is invalid
        // INPUT with a named repair, not the old bare "not valid JSON"
        // death at exit 1. The exception code 2 below is what the
        // console surface turns into the CLI's exit-2 invalid-
        // invocation door; the message says exactly how to recover.
        if (trim($content) === '') {
            throw new \RuntimeException(
                "Baseline file is empty: $path — delete it to take a first-run "
                . "baseline from this run, or re-accept one with --baseline-out $path",
                2,
            );
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(
                "Baseline file is not valid JSON: $path — it is corrupt or truncated; "
                . "restore it from version control or re-accept with --baseline-out $path",
                2,
            );
        }

        if ($decoded === []) {
            throw new \RuntimeException(
                "Baseline file is an empty JSON document ([] carries no baseline): $path — "
                . "delete it to take a first-run baseline from this run, "
                . "or re-accept one with --baseline-out $path",
                2,
            );
        }

        if (!isset($decoded['version'], $decoded['clusters']) || !is_int($decoded['version'])) {
            throw new \RuntimeException("Baseline file has invalid structure: $path");
        }

        if (!in_array($decoded['version'], self::SUPPORTED_VERSIONS, true)) {
            throw new \RuntimeException(
                'Baseline version mismatch: expected ' . implode(' or ', self::SUPPORTED_VERSIONS)
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
     * Read the dispositions map from a baseline file (item 4.4 APPLY
     * half). Lenient by design where the gate read is strict:
     *
     *  - missing file            -> [] (first-run posture)
     *  - unreadable/garbage file -> throws (a corrupt registry must not
     *    silently un-accept decisions that were made deliberately)
     *  - version 3, or a v4 file without the key -> [] (v3 predates
     *    decisions; the file carries none)
     *  - a malformed record      -> loud refusal naming the id and path
     *
     * @return array<string, BaselineDisposition> keyed by finding id
     */
    public function readDispositions(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read baseline file: $path");
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Baseline file is not valid JSON: $path");
        }

        if (!isset($decoded['version']) || !is_int($decoded['version'])) {
            throw new \RuntimeException("Baseline file has invalid structure: $path");
        }

        if (!in_array($decoded['version'], self::SUPPORTED_VERSIONS, true)) {
            throw new \RuntimeException(
                'Baseline version mismatch: expected ' . implode(' or ', self::SUPPORTED_VERSIONS)
                . ", got {$decoded['version']} in $path"
            );
        }

        if (!isset($decoded['dispositions'])) {
            return [];
        }

        if (!is_array($decoded['dispositions'])) {
            throw new \RuntimeException("Baseline 'dispositions' must be an object: $path");
        }

        $result = [];
        foreach ($decoded['dispositions'] as $findingId => $record) {
            if (!is_string($findingId) || $findingId === '') {
                throw new \RuntimeException("Baseline 'dispositions' keys must be non-empty finding ids: $path");
            }
            if (!is_array($record)) {
                throw new \RuntimeException("Baseline disposition '$findingId' must be an object: $path");
            }
            $result[$findingId] = BaselineDisposition::fromArray($findingId, $record, $path);
        }

        return $result;
    }

    /**
     * Build a disposition record for a cluster accepted during this run
     * (source-record posture: provenance is captured at accept time
     * exactly as the doc demands — files + mtimes + git sha if the scan
     * root sits in a repo + the stable member hashes).
     *
     * @param list<Cluster> $acceptedClusters
     * @return array<string, BaselineDisposition>
     */
    public function dispositionsFromClusters(array $acceptedClusters, string $status, ?string $note): array
    {
        $records = [];
        $gitSha = $this->recordGitSha();

        foreach ($acceptedClusters as $cluster) {
            $files = [];
            $mtimes = [];
            foreach ($cluster->members as $block) {
                $canonical = PathCanonical::underRoots($block->file, $this->scanRoots);
                if (in_array($canonical, $files, true)) {
                    continue;
                }
                $files[] = $canonical;
                $mtime = $block->file !== '' ? @filemtime($block->file) : false;
                if ($mtime !== false) {
                    $mtimes[$canonical] = $mtime;
                }
            }

            $memberHashes = $this->computeMemberHashes($cluster);

            $records[$cluster->id] = new BaselineDisposition(
                findingId: $cluster->id,
                status: $status,
                note: $note,
                acceptedAt: date('c'),
                gitSha: $gitSha,
                files: $files,
                mtimes: $mtimes,
                memberHashes: $memberHashes,
            );
        }

        return $records;
    }

    /**
     * Union two disposition sets by finding id; $incoming wins on
     * collision (a decision re-recorded this run supersedes the file's
     * older copy of the same id — the operator's latest word governs).
     *
     * @param array<string, BaselineDisposition> $existing
     * @param array<string, BaselineDisposition> $incoming
     * @return array<string, BaselineDisposition>
     */
    public static function mergeDispositions(array $existing, array $incoming): array
    {
        foreach ($incoming as $findingId => $disposition) {
            $existing[$findingId] = $disposition;
        }
        ksort($existing);
        return $existing;
    }

    /**
     * Best-effort HEAD sha of the first scan root for accept-time
     * provenance. Mirrors Server binary-lookup and RepoLabels precedent:
     * only trust git along an explicit path; absent repo (or absent
     * git) records no sha rather than guessing.
     */
    private function recordGitSha(): ?string
    {
        $root = $this->scanRoots[0] ?? null;
        if ($root === null || !is_dir($root)) {
            return null;
        }

        $git = trim((string) @shell_exec('command -v git 2>/dev/null'));
        if ($git === '') {
            return null;
        }

        $output = [];
        $status = 1;
        @exec(
            escapeshellarg($git) . ' -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null',
            $output,
            $status,
        );
        if ($status !== 0 || $output === []) {
            return null;
        }

        $sha = trim($output[0]);
        return $sha === '' ? null : $sha;
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
