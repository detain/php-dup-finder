<?php
declare(strict_types=1);

namespace Phpdup\Census;

/**
 * Cross-file idiom census (item 3.1, part 2: "count hashes first;
 * keep locations only for hashes seen at least twice").
 *
 * Ingests per-file payloads produced by {@see SubtreeMiner::mineFile()}
 * (wire shape documented there; a scalar JSON string over the mined-row
 * channel), aggregates support, and answers with deterministically
 * ordered rows. Consumer wiring (PreprocessStage dispatch of the
 * 'idiom' flavor, config demand surface, reporting) is a LATER lane per
 * the plan; this class is the substrate they code against — see
 * MINE-C.md 'CONTRACT FOR CONSUMERS'.
 *
 * Memory policy, pinned by tests and load-bearing for the 80GB posture:
 *  - support counters exist for EVERY distinct hash (int + int + a
 *    small file set + abstract-key string);
 *  - `locations` are materialised only once a hash reaches two sites:
 *    the first sighting parks a single pending scalar, the second
 *    pairs them into the sample list; a hash seen at exactly one site
 *    stores ZERO location strings;
 *  - each hash's sample list is capped at `sampleCap` AND
 *    canonicalised: it is kept in ascending 'file:line' order and an
 *    insert that overflows the cap evicts the HIGHEST key, so the
 *    survivors are always the lowest `sampleCap` keys seen —
 *    invariant under the record/interleave order (forked worker-pool
 *    ingest included). Which hashes carry samples at all still
 *    follows the two-pass first-pairing rule above;
 *  - item 3.2 rides the same wire: per-(file,hash) occurrence counts
 *    and the sealed sample-line arrays are retained (a per-file int +
 *    a <= wire-cap list per distinct pair) for helper-span lookup —
 *    rows()/maximalRows() output is untouched by it.
 *
 * The keyspace is the EXACT hash (the letter counts "each hash" — the
 * abstract key rides along on the row for the pseudo-cluster tier).
 * Rows ordered by sites desc, files desc, then exact-hash asc — the
 * FindingId-law tie-break — and with the canonicalised sample lists
 * this means two runs over permuted file order render byte-identical
 * tables: row order, counters AND the per-hash location lists
 * (rows() and, through it, maximalRows()).
 */
final class SubtreeCensus
{
    /** Default per-hash location-sample cap. */
    public const DEFAULT_SAMPLE_CAP = 10;

    /**
     * Wire bound on the sample-line list of one site row — the
     * CONTRACT FOR CONSUMERS shape `lines[≤4]`, matching the miner's
     * sealing cap {@see SubtreeMiner::DEFAULT_ROW_CAP}. A longer row
     * is malformed at the boundary regardless of which producer
     * emitted it.
     */
    public const MAX_WIRE_LINES = 4;

    /**
     * @var array<string, array{
     *     abstract: string, nodes: int, sites: int,
     *     rootTypes: array<string,int>
     * }> exact => support fields
     */
    private array $byExact = [];

    /** @var array<string, array<string, true>> exact => set of files */
    private array $filesByExact = [];

    /** @var array<string, array<string, int>> exact => file => summed occurrences */
    private array $fileSitesByExact = [];

    /**
     * @var array<string, array<string, list<int>>> exact => file => wire
     *      sample lines (arrival order, at most {@see self::MAX_WIRE_LINES}
     *      per site row — the miner's ROW_CAP sealing)
     */
    private array $fileLinesByExact = [];

    /** @var array<string, string|null> pending first location (file:line) */
    private array $pending = [];

    /** @var array<string, list<string>> materialised samples (only >=2 sites) */
    private array $locations = [];

    /** @var array<string, array<string, int>> parentExact => childExact => count */
    private array $edges = [];

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
            throw new \InvalidArgumentException('idiom payload must decode to an array for ' . $file);
        }
        $this->recordFile($file, $decoded);
    }

    /**
     * @param array{sites?: mixed, edges?: mixed} $payload
     */
    public function recordFile(string $file, array $payload): void
    {
        $sites = $payload['sites'] ?? [];
        $edges = $payload['edges'] ?? [];
        if (!is_array($sites) || !is_array($edges)) {
            throw new \InvalidArgumentException('idiom payload sites/edges must be arrays for ' . $file);
        }

        foreach ($sites as $entry) {
            if (
                !is_array($entry) || count($entry) !== 6
                || !is_string($entry[0]) || !is_string($entry[1])
                || !is_int($entry[2]) || !is_string($entry[3]) || !is_int($entry[4])
                || !is_array($entry[5])
            ) {
                throw new \InvalidArgumentException('malformed idiom site row in ' . $file);
            }
            [$exact, $abstract, $nodes, $rootType, $count, $lines] = $entry;
            if ($exact === '' || $count < 1) {
                throw new \InvalidArgumentException('idiom site row needs a key and count>=1 in ' . $file);
            }
            if (count($lines) > self::MAX_WIRE_LINES) {
                throw new \InvalidArgumentException(sprintf(
                    'idiom site row carries %d sample lines, wire cap is %d in %s',
                    count($lines),
                    self::MAX_WIRE_LINES,
                    $file,
                ));
            }
            $this->sitesTotal += $count;
            if (!isset($this->byExact[$exact])) {
                $this->byExact[$exact] = [
                    'abstract'  => $abstract,
                    'nodes'     => $nodes,
                    'sites'     => 0,
                    'rootTypes' => [],
                ];
                $this->pending[$exact] = null;
            } else {
                $this->pinConsistency($exact, $abstract, $nodes);
            }
            $this->byExact[$exact]['sites'] += $count;
            $this->byExact[$exact]['rootTypes'][$rootType] =
                ($this->byExact[$exact]['rootTypes'][$rootType] ?? 0) + $count;
            $this->filesByExact[$exact][$file] = true;
            // Item 3.2 addition: retain the per-(file,hash) occurrence
            // count and the wire's sample lines — the maximal-idiom rows
            // need them for helper-span membership. Counters stay a
            // per-file int (not a per-site list), so the footprint is
            // bounded by distinct (exact, file) pairs already in
            // $filesByExact plus <= MAX_WIRE_LINES ints per pair.
            $this->fileSitesByExact[$exact][$file] =
                ($this->fileSitesByExact[$exact][$file] ?? 0) + $count;
            foreach ($lines as $line) {
                if (!is_int($line)) {
                    throw new \InvalidArgumentException('idiom sample lines must be ints in ' . $file);
                }
                $this->fileLinesByExact[$exact][$file][] = $line;
                $this->noteLocation($exact, $file . ':' . $line);
            }
        }

        foreach ($edges as $entry) {
            if (
                !is_array($entry) || count($entry) !== 3
                || !is_string($entry[0]) || !is_string($entry[1]) || !is_int($entry[2])
            ) {
                throw new \InvalidArgumentException('malformed idiom edge row in ' . $file);
            }
            [$child, $parent, $count] = $entry;
            if ($child === '' || $parent === '' || $count < 1) {
                throw new \InvalidArgumentException('idiom edge row needs keys and count>=1 in ' . $file);
            }
            $this->edges[$parent][$child] = ($this->edges[$parent][$child] ?? 0) + $count;
        }
    }

    /** Distinct enumerated hashes (pass-1 memory footprint). */
    public function hashCount(): int
    {
        return count($this->byExact);
    }

    /** Total occurrences counted across all files. */
    public function sitesTotal(): int
    {
        return $this->sitesTotal;
    }

    public function sitesOf(string $exact): int
    {
        return $this->byExact[$exact]['sites'] ?? 0;
    }

    public function filesOf(string $exact): int
    {
        return isset($this->byExact[$exact]) ? count($this->filesByExact[$exact]) : 0;
    }

    /**
     * Occurrences of one hash per file (item 3.2 helper-span
     * membership). Determinism: summed ints, order-independent; file
     * keys sorted so renderings never depend on ingest order.
     *
     * @return array<string,int> file => occurrences
     */
    public function sitesPerFile(string $exact): array
    {
        $perFile = $this->fileSitesByExact[$exact] ?? [];
        ksort($perFile);
        return $perFile;
    }

    /**
     * Wire sample lines of one hash per file. CAVEAT (pinned by
     * HelperAdoption docs): the miner seals each site row's line list
     * at ROW_CAP, so a file with more than that many same-hash
     * occurrences may not list every line — a helper match via these
     * lines is sufficient but the sampling may under-state how many of
     * its occurrences land in-span.
     *
     * @return array<string,list<int>> file => sample lines (arrival order)
     */
    public function linesPerFile(string $exact): array
    {
        return $this->fileLinesByExact[$exact] ?? [];
    }

    /**
     * All rows, deterministic order, suppression NOT applied (the raw
     * census a consumer may filter by its own thresholds).
     *
     * @return list<IdiomRow>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->byExact as $exact => $b) {
            $rows[] = new IdiomRow(
                exactHash:    $exact,
                abstractHash: $b['abstract'],
                nodes:        $b['nodes'],
                rootType:     self::modeType($b['rootTypes']),
                sites:        $b['sites'],
                files:        count($this->filesByExact[$exact]),
                // Materialise the pending pair-or-scalar once the hash
                // has proven multi-site: >=2 sites with only one sample
                // line still shows its one honest location.
                locations:    $this->locations[$exact]
                    ?? ($b['sites'] >= 2 && $this->pending[$exact] !== null
                        ? [$this->pending[$exact]]
                        : []),
            );
        }
        usort($rows, static fn(IdiomRow $a, IdiomRow $b): int =>
            [$b->sites, $b->files, $a->exactHash] <=> [$a->sites, $a->files, $b->exactHash]
            ?: ($a->exactHash <=> $b->exactHash));
        return $rows;
    }

    /**
     * Rows after the §5c nested-family suppression — THE maximal-idiom
     * census the reporting lane ranks. (Same shared rule 6.3/7.2c
     * consume; support comparison uses the raw sites counter.)
     *
     * @return list<IdiomRow>
     */
    public function maximalRows(): array
    {
        $support = [];
        foreach ($this->byExact as $exact => $b) {
            $support[$exact] = $b['sites'];
        }
        $keep = NestedFamilySuppression::keepMaximal($support, $this->edges);
        $kept = array_flip($keep);

        $rows = [];
        foreach ($this->rows() as $row) {
            if (isset($kept[$row->exactHash])) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Two-pass materialisation: first sighting parks ONE pending
     * scalar; the second pairs it into the capped list. One-site hashes
     * never grow a locations array.
     *
     * Canonicalisation (r1-M1): the pair materialises sorted, and
     * later sightings insert sorted with the highest key evicted over
     * the cap — the surviving sample set (and its rendered order)
     * cannot depend on ingest order.
     */
    private function noteLocation(string $exact, string $location): void
    {
        if (isset($this->locations[$exact])) {
            $this->insertSample($exact, $location);
            return;
        }
        if ($this->pending[$exact] === null) {
            $this->pending[$exact] = $location;
            return;
        }
        $pair = [$this->pending[$exact], $location];
        sort($pair);
        // sampleCap may be 1: the fresh pair must trim to the cap too.
        $this->locations[$exact] = array_slice($pair, 0, $this->sampleCap);
        $this->pending[$exact]   = null; // release the scalar
    }

    /**
     * Ascending insert into the capped list, evicting the highest key
     * on overflow (list stays at most sampleCap + 1 transiently — the
     * same bounded posture an append-until-cap list has). Ties on the
     * key are fine: equal locations render equally either way.
     */
    private function insertSample(string $exact, string $location): void
    {
        /** @var list<string> $list */
        $list = $this->locations[$exact];
        $pos  = count($list);
        while ($pos > 0 && $list[$pos - 1] > $location) {
            $pos--;
        }
        array_splice($list, $pos, 0, [$location]);
        if (count($list) > $this->sampleCap) {
            array_pop($list);
        }
        $this->locations[$exact] = $list;
    }

    /**
     * A hash is content; content cannot change between files. Divergent
     * metadata under the same key means a fold bug or a corrupted
     * payload — halt loudly rather than silently preferring one file's
     * claim (Fail Fast law).
     */
    private function pinConsistency(string $exact, string $abstract, int $nodes): void
    {
        if ($this->byExact[$exact]['abstract'] !== $abstract || $this->byExact[$exact]['nodes'] !== $nodes) {
            throw new \LogicException(sprintf(
                'idiom hash %s carried conflicting abstract/nodes metadata — fold determinism broken',
                substr($exact, 0, 12),
            ));
        }
    }

    /**
     * Most frequent root shape (the "topStmtShape summary"); ties
     * break alphabetically so the summary is run-order-independent.
     *
     * @param array<string,int> $rootTypes
     */
    private static function modeType(array $rootTypes): string
    {
        $best   = '';
        $bestN  = -1;
        foreach ($rootTypes as $type => $n) {
            if ($n > $bestN || ($n === $bestN && $type < $best)) {
                $best  = $type;
                $bestN = $n;
            }
        }
        return $best;
    }
}
