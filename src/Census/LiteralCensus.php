<?php
declare(strict_types=1);

namespace Phpdup\Census;

/**
 * Cross-file literal/constant census (item 3.3, master side): ingests
 * the per-file pre-aggregated rows from {@see LiteralMiner} (wire
 * shape documented there; a scalar JSON string over the mined-row
 * channel) and answers with the ledger the doc asks for — repeated
 * strings, order-insensitive array tables, numbers by position,
 * interpolated-SQL skeletons, and class-const/enum-case decl+use
 * tallies — plus the edit-distance FAMILY view over string entries
 * (bullet 3: "near-duplicate literal families ... drifting allow-lists").
 *
 * Memory policy, the same two-pass law as every census in this
 * namespace: counters (sites/files/roles) exist for EVERY distinct
 * (kind, hash); location samples materialise only at two sites, cap at
 * `sampleCap` with canonical ascending eviction of the HIGHEST key —
 * rows render byte-identical under permuted ingest. Location lines
 * ride the wire's ≤ROW_CAP per-file samples, so the per-(file,entry)
 * line set is a sufficient witness, not an exhaustive list (same
 * caveat the idiom census documents).
 *
 * Report gate per bucket: 'number' additionally needs two FILES (a
 * magic value repeated in one array's positions is not yet the doc's
 * cross-codebase story); everything else needs two SITES. Rows are
 * ordered kind asc, then sites desc, files desc, hash asc (the
 * FindingId-law tie-break).
 */
final class LiteralCensus
{
    /** Default per-entry location-sample cap. */
    public const DEFAULT_SAMPLE_CAP = 10;

    /** Wire bound on the per-file sample-line list (miner's ROW_CAP). */
    public const MAX_WIRE_LINES = 4;

    /** A bucket entry reports once seen at this many sites. */
    public const MIN_SITES = 2;

    /** 'number' entries also need this many distinct files. */
    public const NUMBER_MIN_FILES = 2;

    /** Family pass skipped above this many distinct string entries. */
    public const FAMILY_MAX_DISTINCT = 400;

    /** PHP levenshtein bound (bytes); longer samples take no part. */
    public const FAMILY_MAX_LEN = 255;

    /** Family members listed per family. */
    public const FAMILY_MEMBER_CAP = 10;

    /**
     * @var array<string, array{kind:string,hash:string,sample:string,sites:int}> key => counters
     */
    private array $byKey = [];

    /** @var array<string, array<string, true>> key => set of files */
    private array $filesByKey = [];

    /** @var array<string, array<string,int>> key => role => occurrences */
    private array $rolesByKey = [];

    /** @var array<string, string|null> pending first location */
    private array $pending = [];

    /** @var array<string, list<string>> materialised samples (only >=2 sites) */
    private array $locations = [];

    private int $sitesTotal = 0;

    public function __construct(public readonly int $sampleCap = self::DEFAULT_SAMPLE_CAP)
    {
        if ($sampleCap < 1) {
            throw new \InvalidArgumentException('sampleCap must be >= 1, got ' . $sampleCap);
        }
    }

    /**
     * Boundary parse: accept the raw wire JSON, validate ONCE here, and
     * trust the decoded shape internally.
     */
    public function recordFileJson(string $file, string $payloadJson): void
    {
        $decoded = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('literal payload must decode to an array for ' . $file);
        }
        $this->recordFile($file, $decoded);
    }

    /**
     * @param list<array{0?: mixed, 1?: mixed, 2?: mixed, 3?: mixed, 4?: mixed, 5?: mixed}> $rows
     */
    public function recordFile(string $file, array $rows): void
    {
        foreach ($rows as $entry) {
            if (
                !is_array($entry) || count($entry) !== 6
                || !is_string($entry[0]) || !is_string($entry[1]) || !is_string($entry[2])
                || !is_string($entry[3]) || !is_int($entry[4]) || !is_array($entry[5])
            ) {
                throw new \InvalidArgumentException('malformed literal row in ' . $file);
            }
            [$kind, $hash, $sample, $role, $count, $lines] = $entry;
            if ($kind === '' || $hash === '' || $count < 1) {
                throw new \InvalidArgumentException('literal row needs kind/hash/count>=1 in ' . $file);
            }
            $key = $kind . "\0" . $hash;
            if (!isset($this->byKey[$key])) {
                $this->byKey[$key]    = ['kind' => $kind, 'hash' => $hash, 'sample' => $sample, 'sites' => 0];
                $this->rolesByKey[$key] = [];
                $this->pending[$key]  = null;
            } elseif ($this->byKey[$key]['sample'] !== $sample) {
                // Fail Fast: an entry hash is content; content cannot
                // change between files (fold bug or corrupted payload).
                throw new \LogicException(sprintf(
                    'literal hash %s:%s carried conflicting samples — census determinism broken',
                    $kind,
                    substr($hash, 0, 12),
                ));
            }
            $this->byKey[$key]['sites'] += $count;
            $this->sitesTotal           += $count;
            $this->filesByKey[$key][$file] = true;
            $this->rolesByKey[$key][$role] = ($this->rolesByKey[$key][$role] ?? 0) + $count;
            foreach ($lines as $line) {
                if (!is_int($line)) {
                    throw new \InvalidArgumentException('literal sample lines must be ints in ' . $file);
                }
                $this->noteLocation($key, $file . ':' . $line);
            }
        }
    }

    /** Distinct entries enumerated (memory footprint gauge). */
    public function entryCount(): int
    {
        return count($this->byKey);
    }

    /** Total literal occurrences counted across all files. */
    public function sitesTotal(): int
    {
        return $this->sitesTotal;
    }

    public function sitesOf(string $kind, string $hash): int
    {
        return $this->byKey[$kind . "\0" . $hash]['sites'] ?? 0;
    }

    /**
     * All reportable entries, deterministic order (thresholds per the
     * class law).
     *
     * @return list<LiteralEntryRow>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->byKey as $key => $b) {
            $files = count($this->filesByKey[$key]);
            if ($b['sites'] < self::MIN_SITES) {
                continue;
            }
            if ($b['kind'] === 'number' && $files < self::NUMBER_MIN_FILES) {
                continue;
            }
            $rows[] = new LiteralEntryRow(
                kind:      $b['kind'],
                hash:      $b['hash'],
                sample:    $b['sample'],
                roles:     $this->rolesByKey[$key],
                sites:     $b['sites'],
                files:     $files,
                locations: $this->locations[$key]
                    ?? ($this->pending[$key] !== null ? [$this->pending[$key]] : []),
            );
        }
        usort($rows, static fn(LiteralEntryRow $a, LiteralEntryRow $b): int =>
            [$a->kind, $b->sites, $b->files, $a->hash] <=> [$b->kind, $a->sites, $a->files, $b->hash]);
        return $rows;
    }

    /**
     * Per-bucket totals (entries counted at every support level, sites
     * over ALL occurrences): the summary the armed JSON block carries.
     *
     * @return array<string, array{entries:int,sites:int}>
     */
    public function bucketTotals(): array
    {
        $totals = [];
        foreach (['string', 'array', 'number', 'skeleton', 'const'] as $kind) {
            $totals[$kind] = ['entries' => 0, 'sites' => 0];
        }
        foreach ($this->byKey as $b) {
            $totals[$b['kind']]['entries']++;
            $totals[$b['kind']]['sites']   += $b['sites'];
        }
        return $totals;
    }

    /**
     * Near-duplicate string families (bullet 3): clusters of distinct
     * string samples within a drifting-edit budget — the doc's
     * "allow-list copied and each copy adds one item".
     *
     * Deterministic: entries are visited in (kind,hash) key order, the
     * union-find merges in that order too, families sort by total sites
     * desc then first member asc. Bounded two ways: the pass is SKIPPED
     * (familiesEmpty() === with computed=false) above
     * {@see FAMILY_MAX_DISTINCT} distinct string entries, and samples
     * longer than PHP's levenshtein bound take no part.
     *
     * @return list<array{members:list<string>,sites:int,files:int}>
     */
    public function families(): array
    {
        if (!$this->familiesComputed()) {
            return [];
        }
        /** @var list<string> $keys string-bucket keys in census order */
        $keys = [];
        foreach ($this->byKey as $key => $b) {
            if ($b['kind'] === 'string') {
                $keys[] = $key;
            }
        }
        usort($keys, static fn(string $a, string $b): int => $a <=> $b);

        /** @var array<string,string> $parent key => key (self = root) */
        $parent = [];
        foreach ($keys as $key) {
            $parent[$key] = $key;
        }
        $find = static function (string $x) use (&$parent): string {
            while ($parent[$x] !== $x) {
                $x = $parent[$x];
            }
            return $x;
        };
        $union = static function (string $a, string $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb; // merge toward the later scan root; order-normalised below
            }
        };

        $n = count($keys);
        for ($i = 0; $i < $n; $i++) {
            $va = $this->byKey[$keys[$i]]['sample'];
            if (strlen($va) > self::FAMILY_MAX_LEN) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                $vb = $this->byKey[$keys[$j]]['sample'];
                if (strlen($vb) > self::FAMILY_MAX_LEN) {
                    continue;
                }
                $maxLen = max(strlen($va), strlen($vb));
                $minLen = min(strlen($va), strlen($vb));
                if ($maxLen - $minLen > max(2, (int) ceil(0.2 * $maxLen))) {
                    continue; // outside the length band: can't be close
                }
                $distance = levenshtein($va, $vb);
                if ($distance <= max(2, (int) floor(0.2 * $minLen))) {
                    $union($keys[$i], $keys[$j]);
                }
            }
        }

        $groups = [];
        foreach ($keys as $key) {
            $root = $find($key);
            $groups[$root][] = $key;
        }
        $families = [];
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }
            usort($members, static fn(string $a, string $b): int => $a <=> $b);
            $sites  = 0;
            $files  = [];
            $listed = [];
            foreach ($members as $key) {
                $sites += $this->byKey[$key]['sites'];
                foreach (array_keys($this->filesByKey[$key]) as $file) {
                    $files[$file] = true;
                }
                $sample = $this->byKey[$key]['sample'];
                if (!in_array($sample, $listed, true) && count($listed) < self::FAMILY_MEMBER_CAP) {
                    $listed[] = $sample;
                }
            }
            sort($listed);
            $families[] = ['members' => $listed, 'sites' => $sites, 'files' => count($files)];
        }
        usort($families, static fn(array $a, array $b): int =>
            [-$a['sites'], $a['members'][0]] <=> [-$b['sites'], $b['members'][0]]
            ?: ($a['members'][0] <=> $b['members'][0]));
        return $families;
    }

    /** Whether the family pass is affordable this run (cap breach = false). */
    public function familiesComputed(): bool
    {
        $strings = 0;
        foreach ($this->byKey as $b) {
            if ($b['kind'] === 'string' && ++$strings > self::FAMILY_MAX_DISTINCT) {
                return false;
            }
        }
        return true;
    }

    /**
     * Two-pass materialisation (SubtreeCensus law): first sighting
     * parks one pending scalar, the second pairs them sorted; later
     * sightings insert ascending, evicting the highest key over cap.
     */
    private function noteLocation(string $key, string $location): void
    {
        if (isset($this->locations[$key])) {
            $this->insertSample($key, $location);
            return;
        }
        if ($this->pending[$key] === null) {
            $this->pending[$key] = $location;
            return;
        }
        $pair = [$this->pending[$key], $location];
        sort($pair);
        $this->locations[$key] = array_slice($pair, 0, $this->sampleCap);
        $this->pending[$key]   = null;
    }

    /**
     * Ascending insert, evicting the highest key on overflow (ties are
     * fine: equal locations render equally either way).
     */
    private function insertSample(string $key, string $location): void
    {
        $list = $this->locations[$key];
        $pos  = count($list);
        while ($pos > 0 && $list[$pos - 1] > $location) {
            $pos--;
        }
        array_splice($list, $pos, 0, [$location]);
        if (count($list) > $this->sampleCap) {
            array_pop($list);
        }
        $this->locations[$key] = $list;
    }
}
