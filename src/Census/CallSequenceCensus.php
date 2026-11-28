<?php
declare(strict_types=1);

namespace Phpdup\Census;

/**
 * Cross-file call-protocol census (item 3.5): aggregates the ordered
 * callee-name lists captured per file by {@see CallSequenceMiner}
 * (wire shape documented there) into shared PROTOCOLS — contiguous
 * windows of length MIN_WINDOW..MAX_WINDOW over each list — with the
 * doc's exact report posture: "Report each protocol with its sites and
 * a table of constant arguments that differ."
 *
 * Memory policy mirrors {@see SubtreeCensus} (the two-pass law that
 * keeps the 80GB posture alive):
 *  - support counters (sites, files, per-slot argument witnesses,
 *    extension edges) exist for EVERY distinct window key — ints and
 *    small sets only;
 *  - location samples materialise only once a window reaches two
 *    sites (first sighting parks one pending occurrence, the second
 *    pairs them), capped at `sampleCap` with the canonical ascending
 *    'file:line' insert evicting the HIGHEST key on overflow — the
 *    surviving sample is invariant under record/interleave order
 *    (forked worker-pool ingest included);
 *  - the per-slot constant-argument table is corpus-COMPLETE: the
 *    miner already caps one occurrence's tokens, so counting every
 *    occurrence's tokens per (window, slot) stays bounded by token
 *    variety, not corpus size (the 79-false/1-true cURL story renders
 *    in full, not through the sample).
 *
 * Nested-family suppression (§5c) is the SAME shared rule the idiom
 * census runs: maximalRows() drops a window contained in an equally-
 * or more-frequent larger window (edges are the immediate extensions
 * observed at each site — [a,b] sits under [x,a,b] and [a,b,y]), so a
 * four-call protocol reports once instead of drowning its own prefixes
 * and infixes in noise.
 *
 * Rows are ordered sites desc, files desc, then key asc (the
 * FindingId-law tie-break); keys join names with NUL — a byte no
 * qualified PHP name can contain — so the sort is pure content order.
 *
 * The name index (per distinct callee token: sites + per-file lines
 * bounded below) additionally answers the item 3.2 follow-on: helper
 * CALLER counts ("Env::bool() — 6 callers, 9 inline re-implementations"
 * — the first half was missing until this leg).
 */
final class CallSequenceCensus
{
    /** Default per-window location-sample cap. */
    public const DEFAULT_SAMPLE_CAP = 10;

    /** Shortest protocol mined (the letter's "sequences", plural). */
    public const MIN_WINDOW = 2;

    /** Longest protocol window enumerated. */
    public const MAX_WINDOW = 4;

    /** A protocol reports once seen at this many sites (doc: >=2). */
    public const MIN_SITES = 2;

    /** Per-(token, file) call lines retained for span-exclusion. */
    public const MAX_LINES_PER_NAME_FILE = 16;

    /**
     * @var array<string, array{names: list<string>, length: int, sites: int}> key => counters
     */
    private array $byKey = [];

    /** @var array<string, array<string, true>> key => set of files */
    private array $filesByKey = [];

    /** @var array<string, array<int, array<string, int>>> key => slot => token => occurrences */
    private array $argBySlot = [];

    /** @var array<string, array<int, int>> key => slot => occurrences with NO token there */
    private array $argNone = [];

    /** @var array<string, null|array{file: string, line: int}> pending first occurrence (only >=2 materialise) */
    private array $pending = [];

    /**
     * @var array<string, list<array{file: string, line: int}>> materialised
     *      samples (only >=2 sites); parallel counters never store args
     *      here — the corpus-complete arg tables above replace sampling.
     */
    private array $locations = [];

    /** @var array<string, array<string, int>> parentKey => childKey => co-occurrences */
    private array $edges = [];

    /**
     * @var array<string, array{sites: int, lines: array<string, list<int>>, overflow: array<string, int>}>
     *      callee token => occurrence counters (helper caller join)
     */
    private array $nameIndex = [];

    private int $sitesTotal = 0;

    public function __construct(public readonly int $sampleCap = self::DEFAULT_SAMPLE_CAP)
    {
        if ($sampleCap < 1) {
            throw new \InvalidArgumentException('sampleCap must be >= 1, got ' . $sampleCap);
        }
    }

    /**
     * Boundary parse: accept the raw wire JSON, validate ONCE here, and
     * trust the decoded shape internally (mirrors the idiom census).
     */
    public function recordFileJson(string $file, string $payloadJson): void
    {
        $decoded = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('calls payload must decode to an array for ' . $file);
        }
        $this->recordFile($file, $decoded);
    }

    /**
     * @param list<array{names?: mixed, lines?: mixed, args?: mixed, firstLine?: mixed, lastLine?: mixed}> $records
     */
    public function recordFile(string $file, array $records): void
    {
        foreach ($records as $record) {
            $this->recordList($file, $record);
        }
    }

    /**
     * @param array{names?: mixed, lines?: mixed, args?: mixed, firstLine?: mixed, lastLine?: mixed} $record
     */
    private function recordList(string $file, array $record): void
    {
        $names = $record['names'] ?? null;
        $lines = $record['lines'] ?? null;
        $args  = $record['args'] ?? null;
        if (!is_array($names) || !is_array($lines) || !is_array($args)) {
            throw new \InvalidArgumentException('calls record needs names/lines/args arrays in ' . $file);
        }
        $n = count($names);
        if ($n === 0 || count($lines) !== $n || count($args) !== $n) {
            throw new \InvalidArgumentException('calls record fields must be index-parallel in ' . $file);
        }
        $flat = [];
        foreach ($names as $i => $name) {
            if (!is_string($name) || $name === '' || !is_int($lines[$i])) {
                throw new \InvalidArgumentException('calls record names/lines must be string/int in ' . $file);
            }
            $argRow = $args[$i];
            if (!is_array($argRow)) {
                throw new \InvalidArgumentException('calls record args must be lists in ' . $file);
            }
            $tokens = [];
            foreach ($argRow as $token) {
                // Wire-format guard (CRITICAL-1 era): an EMPTY token is
                // a producer bug, never data — the miner spells real
                // empty-string arguments as "''" so legitimate shapes
                // flow through and this refusal stays meaningful.
                if (!is_string($token) || $token === '') {
                    throw new \InvalidArgumentException('calls record arg tokens must be non-empty strings in ' . $file);
                }
                $tokens[] = $token;
            }
            $flat[] = ['name' => $name, 'line' => $lines[$i], 'tokens' => $tokens];
        }

        // Name index: every named call occurrence once (helper join).
        foreach ($flat as $call) {
            $this->noteName($call['name'], $file, $call['line']);
        }

        // Window enumeration (contiguous, MIN_WINDOW..MAX_WINDOW).
        for ($k = self::MIN_WINDOW; $k <= self::MAX_WINDOW && $k <= $n; $k++) {
            for ($i = 0; $i + $k <= $n; $i++) {
                $slice = array_slice($flat, $i, $k);
                $key   = implode("\0", array_map(static fn(array $c): string => $c['name'], $slice));
                if (!isset($this->byKey[$key])) {
                    $meta = ['names' => array_map(static fn(array $c): string => $c['name'], $slice), 'length' => $k, 'sites' => 0];
                    $this->byKey[$key]      = $meta;
                    $this->argBySlot[$key]  = [];
                    $this->argNone[$key]    = [];
                    $this->pending[$key]    = null;
                }
                $this->byKey[$key]['sites']++;
                $this->sitesTotal++;
                $this->filesByKey[$key][$file] = true;
                foreach ($slice as $j => $call) {
                    if ($call['tokens'] === []) {
                        $this->argNone[$key][$j] = ($this->argNone[$key][$j] ?? 0) + 1;
                        continue;
                    }
                    foreach ($call['tokens'] as $token) {
                        $this->argBySlot[$key][$j][$token] = ($this->argBySlot[$key][$j][$token] ?? 0) + 1;
                    }
                }
                $this->noteLocation($key, ['file' => $file, 'line' => $slice[0]['line']]);

                // Edges to the immediate extensions existing at this site.
                if ($k < self::MAX_WINDOW) {
                    if ($i > 0) {
                        $left = $flat[$i - 1]['name'] . "\0" . $key;
                        $this->edges[$left][$key] = ($this->edges[$left][$key] ?? 0) + 1;
                    }
                    if ($i + $k < $n) {
                        $right = $key . "\0" . $flat[$i + $k]['name'];
                        $this->edges[$right][$key] = ($this->edges[$right][$key] ?? 0) + 1;
                    }
                }
            }
        }
    }

    /** Distinct enumerated windows (memory footprint gauge). */
    public function windowCount(): int
    {
        return count($this->byKey);
    }

    /** Total window occurrences counted across all files. */
    public function sitesTotal(): int
    {
        return $this->sitesTotal;
    }

    public function sitesOf(string $key): int
    {
        return $this->byKey[$key]['sites'] ?? 0;
    }

    public function filesOf(string $key): int
    {
        return isset($this->filesByKey[$key]) ? count($this->filesByKey[$key]) : 0;
    }

    /**
     * All protocol rows (suppression NOT applied — the raw census a
     * consumer may filter by its own thresholds), deterministic order.
     *
     * @return list<CallProtocolRow>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->byKey as $key => $b) {
            if ($b['sites'] < self::MIN_SITES) {
                continue;
            }
            $rows[] = new CallProtocolRow(
                names:     $b['names'],
                length:    $b['length'],
                sites:     $b['sites'],
                files:     count($this->filesByKey[$key]),
                locations: $this->sampleLocations($key),
                argDiff:   $this->argDiffFor($key),
            );
        }
        usort($rows, static fn(CallProtocolRow $a, CallProtocolRow $b): int =>
            [$b->sites, $b->files, $a->names] <=> [$a->sites, $a->files, $b->names]);
        return $rows;
    }

    /**
     * Rows after the §5c nested-family suppression — THE maximal-
     * protocol census the reporting lane renders (a window drops iff
     * some observed larger window containing it is equally or more
     * frequent; chains fall out with no recursion, no order
     * dependence — shared rule, same as idioms).
     *
     * @return list<CallProtocolRow>
     */
    public function maximalRows(): array
    {
        $support = [];
        foreach ($this->byKey as $key => $b) {
            $support[$key] = $b['sites'];
        }
        $keep = array_flip(NestedFamilySuppression::keepMaximal($support, $this->edges));

        $rows = [];
        foreach ($this->rows() as $row) {
            if (isset($keep[implode("\0", $row->names)])) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Call-site counters for one helper method (item 3.2 follow-on:
     * the "6 callers" half). v1 join is receiver-blind on purpose:
     * a token matches when it ends `::method` (class part ignored —
     * `Foo::m`, `\A\Foo::m` and `self::m` all count) or equals the
     * bare method name (MethodCall capture is receiver-blind, and a
     * same-named global function joins too — over-count risk,
     * disclosed). PHP method names are case-insensitive; matching
     * folds case (the miner keeps as-written spellings).
     *
     * Occurrences inside the helper's own definition span are
     * excluded so recursion never self-counts; the exclusion reads
     * the per-(token,file) line lists capped at
     * {@see self::MAX_LINES_PER_NAME_FILE} — beyond the cap the
     * overflowed occurrences all count as EXTERNAL (worst honest
     * direction, disclosed).
     *
     * @return array{callers: int, locations: list<string>}
     */
    public function sitesForMethod(string $method, string $defFile = '', int $defStart = 0, int $defEnd = 0): array
    {
        $needle    = strtolower($method);
        $callers   = 0;
        $locations = [];
        foreach ($this->nameIndex as $token => $entry) {
            $bare    = strtolower($token);
            $slash   = strrpos($bare, '\\');
            $short   = $slash === false ? $bare : substr($bare, (int) $slash + 1);
            $tailPos = strrpos($bare, '::');
            $tail    = $tailPos === false ? $bare : substr($bare, (int) $tailPos + 2);
            // Match: bare or namespace-qualified function spelling
            // ('getenv', '\getenv'), or any `Class::method` spelling
            // (receiver-blind — the class part is not consulted).
            $hit = $short === $needle || $tail === $needle;
            if (!$hit) {
                continue;
            }
            foreach ($entry['lines'] as $file => $lines) {
                $external = 0;
                foreach ($lines as $line) {
                    if ($defFile !== '' && $file === $defFile && $line >= $defStart && $line <= $defEnd) {
                        continue; // the helper's own body: not its caller
                    }
                    $external++;
                    $locations[] = $file . ':' . $line;
                }
                $external += $entry['overflow'][$file] ?? 0; // capped lines count as external
                $callers  += $external;
            }
        }
        sort($locations);
        return [
            'callers'   => $callers,
            'locations' => array_slice($locations, 0, $this->sampleCap),
        ];
    }

    /** Distinct callee tokens indexed (gauge for probes/tests). */
    public function tokenCount(): int
    {
        return count($this->nameIndex);
    }

    /**
     * Item 6.4 (THEME): the distinct callee tokens of one file's line
     * window — the theming lane's span-scoped vocabulary read over the
     * SAME name index the helper-caller join above uses (no second
     * mining pass, §5c one-walker law).
     *
     * A token matches when any retained line for (token, file) falls
     * inside [startLine, endLine], OR the (token, file) list overflowed
     * the cap — then its unretained occurrences count as INSIDE, the
     * opposite assumption to {@see self::sitesForMethod()}'s exclusion
     * (there, overflow counted EXTERNAL so caller counts could not
     * inflate; here, over-inclusion is the worst honest direction for a
     * theme seed: a 17-call-per-file helper is exactly the vocabulary
     * signal a theme wants, and dropping it would silently thin the
     * grouping). Disclosed, like the sibling law.
     *
     * Tokens are returned as-written (the miner's spelling), ascending.
     *
     * @return list<string>
     */
    public function calleeTokensIn(string $file, int $startLine, int $endLine): array
    {
        $hits = [];
        foreach ($this->nameIndex as $token => $entry) {
            $member = false;
            foreach ($entry['lines'][$file] ?? [] as $line) {
                if ($line >= $startLine && $line <= $endLine) {
                    $member = true;
                    break;
                }
            }
            if (!$member && isset($entry['overflow'][$file])) {
                $member = true;
            }
            if ($member) {
                $hits[] = (string) $token;
            }
        }
        sort($hits);
        return $hits;
    }

    // ---------------------------------------------------------------- state

    /**
     * Two-pass materialisation (SubtreeCensus law, verbatim posture):
     * first sighting parks one pending scalar; the second pairs them
     * sorted; later sightings insert ascending, evicting the HIGHEST
     * key over the cap. A one-site window never grows a list.
     *
     * @param array{file: string, line: int} $occurrence
     */
    private function noteLocation(string $key, array $occurrence): void
    {
        $location = $occurrence['file'] . ':' . $occurrence['line'];
        if (isset($this->locations[$key])) {
            $this->insertSample($key, $occurrence);
            return;
        }
        if ($this->pending[$key] === null) {
            $this->pending[$key] = $occurrence;
            return;
        }
        $pair = [$this->pending[$key], $occurrence];
        usort($pair, self::compareOccurrence(...));
        // sampleCap may be 1: the fresh pair must trim to the cap too.
        $this->locations[$key] = array_slice($pair, 0, $this->sampleCap);
        $this->pending[$key]   = null; // release the scalar
    }

    /**
     * Ascending insert into the capped list, evicting the highest key
     * on overflow (ties on 'file:line' are fine: equal locations
     * render equally either way).
     *
     * @param array{file: string, line: int} $occurrence
     */
    private function insertSample(string $key, array $occurrence): void
    {
        $list = $this->locations[$key];
        $pos  = count($list);
        while ($pos > 0 && self::compareOccurrence($list[$pos - 1], $occurrence) > 0) {
            $pos--;
        }
        array_splice($list, $pos, 0, [$occurrence]);
        if (count($list) > $this->sampleCap) {
            array_pop($list);
        }
        $this->locations[$key] = $list;
    }

    /**
     * @param array{file: string, line: int} $a
     * @param array{file: string, line: int} $b
     */
    private static function compareOccurrence(array $a, array $b): int
    {
        return [$a['file'], $a['line']] <=> [$b['file'], $b['line']];
    }

    /**
     * @return list<string>
     */
    private function sampleLocations(string $key): array
    {
        $list = $this->locations[$key] ?? [];
        return array_map(static fn(array $o): string => $o['file'] . ':' . $o['line'], $list);
    }

    /**
     * Slot-major differing-argument table from the corpus-complete
     * counters (values cap {@see CallProtocolRow::ARG_VALUES_PER_SLOT}
     * per slot, count desc then token asc; `none` rides the holes).
     *
     * @return list<array{slot:int,call:string,values:list<array{token:string,sites:int}>,none:int}>
     */
    private function argDiffFor(string $key): array
    {
        $table = [];
        foreach ($this->byKey[$key]['names'] as $slot => $callName) {
            $counts = $this->argBySlot[$key][$slot] ?? [];
            $none   = $this->argNone[$key][$slot] ?? 0;
            if ($counts === []) {
                continue; // nothing to differ: the slot is pure hole
            }
            if (count($counts) < 2 && $none === 0) {
                continue; // one universal constant across all sites: no difference to table
            }
            $order = array_keys($counts);
            usort($order, static fn(string $a, string $b): int =>
                [$counts[$b], $a] <=> [$counts[$a], $b]);
            $values = [];
            foreach (array_slice($order, 0, CallProtocolRow::ARG_VALUES_PER_SLOT) as $token) {
                $values[] = ['token' => $token, 'sites' => $counts[$token]];
            }
            $table[] = ['slot' => $slot, 'call' => $callName, 'values' => $values, 'none' => $none];
        }
        return $table;
    }

    private function noteName(string $token, string $file, int $line): void
    {
        if (!isset($this->nameIndex[$token])) {
            $this->nameIndex[$token] = ['sites' => 0, 'lines' => [], 'overflow' => []];
        }
        $this->nameIndex[$token]['sites']++;
        $lines = $this->nameIndex[$token]['lines'][$file] ?? [];
        if (count($lines) < self::MAX_LINES_PER_NAME_FILE) {
            $lines[] = $line;
            $this->nameIndex[$token]['lines'][$file] = $lines;
            return;
        }
        $this->nameIndex[$token]['overflow'][$file] =
            ($this->nameIndex[$token]['overflow'][$file] ?? 0) + 1;
    }
}
