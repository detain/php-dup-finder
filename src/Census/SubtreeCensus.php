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
 *  - each hash's sample list is capped at `sampleCap` — bounded per
 *    distinct hash no matter how often it repeats.
 *
 * The keyspace is the EXACT hash (the letter counts "each hash" — the
 * abstract key rides along on the row for the pseudo-cluster tier).
 * Rows ordered by sites desc, files desc, then exact-hash asc — the
 * FindingId-law tie-break so two runs over permuted file order render
 * byte-identical tables.
 */
final class SubtreeCensus
{
    /** Default per-hash location-sample cap. */
    public const DEFAULT_SAMPLE_CAP = 10;

    /**
     * @var array<string, array{
     *     abstract: string, nodes: int, sites: int,
     *     rootTypes: array<string,int>
     * }> exact => support fields
     */
    private array $byExact = [];

    /** @var array<string, array<string, true>> exact => set of files */
    private array $filesByExact = [];

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

            foreach ($lines as $line) {
                if (!is_int($line)) {
                    throw new \InvalidArgumentException('idiom sample lines must be ints in ' . $file);
                }
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
     */
    private function noteLocation(string $exact, string $location): void
    {
        if (isset($this->locations[$exact])) {
            if (count($this->locations[$exact]) < $this->sampleCap) {
                $this->locations[$exact][] = $location;
            }
            return;
        }
        if ($this->pending[$exact] === null) {
            $this->pending[$exact] = $location;
            return;
        }
        $this->locations[$exact] = [$this->pending[$exact], $location];
        $this->pending[$exact]   = null; // release the scalar
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
