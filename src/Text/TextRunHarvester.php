<?php
declare(strict_types=1);

namespace Phpdup\Text;

use Phpdup\Fingerprint\NgramFingerprint;

/**
 * Item 7.1: cross-file maximal-match harvest over blanked token streams.
 *
 * The algorithm, in one breath: hash k-token windows (the SAME roll-window
 * algebra the AST bags use — {@see NgramFingerprint::windows()}, one
 * formula law), let shared window keys seed file pairs, extend every seed
 * left and right over the blanked streams to a maximal agreement, merge
 * overlapping runs per file, and emit what clears the floors.
 *
 * Guards, all disclosed constants:
 *  - {@see MAX_DF}: a window key living in more files than this is
 *    boilerplate (doctype lines, `{`-runs) — it seeds nothing;
 *  - {@see MAX_PAIRS}: runaway pair guard for pathological corpora;
 *    the cap degrades harvest coverage honestly (a note is emitted);
 *  - {@see MAX_SEEDS_PER_PAIR}/{@see MAX_POSITION_MATCHES}: a clone family
 *    repeated many times needs ONE seed to extend to the maximal run, so
 *    pair work is evenly-sampled and bounded, never exhaustive;
 *  - {@see MIN_RUN_TOKENS}/{@see MIN_RUN_LINES}: floors below which a run
 *    is punctuation noise, not a clone.
 *
 * Determinism: survivors and pair seeds are sorted before iteration, so
 * harvest output depends on file CONTENT only, never on map insertion
 * order. The pair-formation scan order (file-index order) is itself fixed.
 */
final class TextRunHarvester
{
    /** Smallest run that reports — 24 blanked tokens is a real statement, not a call. */
    public const MIN_RUN_TOKENS = 24;

    /** A run must span at least this many source lines (copy-paste is a multi-line act). */
    public const MIN_RUN_LINES = 4;

    /** Window keys appearing in more files than this are boilerplate; they never seed. */
    public const MAX_DF = 64;

    /** Runaway guard on candidate file-pairs (disclosed via a harvest note when hit). */
    public const MAX_PAIRS = 200_000;

    /** Evenly-spaced window seeds taken per candidate pair. */
    public const MAX_SEEDS_PER_PAIR = 40;

    /** Position cross-product budget per seed key per pair. */
    public const MAX_POSITION_MATCHES = 64;

    /** @var list<string> human-readable disclosure rows (caps hit etc.); CLI surfaces them verbose */
    private array $notes = [];

    /** @param int<2, max> $n window width — the tier's configured ngram_size, one knob with the AST bags */
    public function __construct(private readonly int $n = 5)
    {
    }

    /**
     * @param list<TextFileTokens> $files
     * @return list<TextRun> maximal runs, one per (file, merged interval)
     */
    public function harvest(array $files): array
    {
        /** @var array<int, array<string, list<int>>> $pos  file index → window key → start offsets */
        $pos = [];
        /** @var array<int, array<string, true>> $seen  file index → distinct keys (df census) */
        $seen = [];
        foreach ($files as $fi => $ft) {
            $keys = NgramFingerprint::windows($ft->blanked(), $this->n);
            if ($keys === []) {
                continue; // shorter than one window: nothing to seed, nothing to say
            }
            foreach ($keys as $offset => $key) {
                $pos[$fi][$key][] = $offset;
                if (!isset($seen[$fi][$key])) {
                    $seen[$fi][$key] = true;
                }
            }
        }

        /** @var array<string, int> $df */
        $df = [];
        foreach ($seen as $perFile) {
            foreach ($perFile as $key => $_) {
                $df[$key] = ($df[$key] ?? 0) + 1;
            }
        }

        /** @var array<string, list<int>> $byKey  survivors only, key → files containing it */
        $byKey = [];
        foreach ($seen as $fi => $perFile) {
            foreach ($perFile as $key => $_) {
                if ($df[$key] >= 2 && $df[$key] <= self::MAX_DF) {
                    $byKey[$key][] = $fi;
                }
            }
        }
        ksort($byKey);

        // Pair formation: files sharing at least one surviving key become
        // candidate pairs, carrying their shared-key set for seeding.
        /** @var array<string, array<string, true>> $pairKeys  "a\x1Fb" → shared keys */
        $pairKeys = [];
        $pairCount = 0;
        foreach ($byKey as $key => $fl) {
            $c = count($fl);
            for ($x = 0; $x < $c; $x++) {
                for ($y = $x + 1; $y < $c; $y++) {
                    $id = $fl[$x] . "\x1F" . $fl[$y];
                    if (!isset($pairKeys[$id])) {
                        if ($pairCount >= self::MAX_PAIRS) {
                            $this->notes[] = sprintf(
                                'token tier: candidate-pair cap %d reached; remaining files kept their seed keys but formed no new pairs this run',
                                self::MAX_PAIRS,
                            );
                            break 3;
                        }
                        $pairKeys[$id] = [];
                        $pairCount++;
                    }
                    $pairKeys[$id][$key] = true;
                }
            }
        }

        /** @var array<int, list<array{0:int, 1:int}>> $intervals  file index → [start, end) */
        $intervals = [];
        foreach ($pairKeys as $id => $keySet) {
            [$a, $b] = explode("\x1F", $id);
            $fa = $files[(int) $a];
            $fb = $files[(int) $b];
            $this->extendPair($fa->blanked(), $fb->blanked(), $pos[(int) $a], $pos[(int) $b], array_keys($keySet), $intervals, (int) $a, (int) $b);
        }

        // Merge overlapping/adjacent intervals per file, then floor-check.
        $runs = [];
        foreach ($intervals as $fi => $list) {
            usort($list, static fn (array $x, array $y): int => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);
            $ft   = $files[$fi];
            $lines = $ft->lines();
            $cur   = null;
            foreach ($list as $iv) {
                if ($cur === null) {
                    $cur = $iv;
                    continue;
                }
                if ($iv[0] <= $cur[1]) {
                    $cur[1] = max($cur[1], $iv[1]); // overlap or touch — merge
                    continue;
                }
                $runs[] = $this->emit($ft, $lines, $cur[0], $cur[1]);
                $cur    = $iv;
            }
            if ($cur !== null) {
                $runs[] = $this->emit($ft, $lines, $cur[0], $cur[1]);
            }
        }

        return array_values(array_filter($runs));
    }

    /**
     * LCE extension for one candidate pair: every evenly-spaced seed key
     * extends its shared window positions to a maximal agreement; the
     * longest accepted run per pair is recorded on BOTH files.
     *
     * @param list<string> $ta
     * @param list<string> $tb
     * @param array<string, list<int>> $pa
     * @param array<string, list<int>> $pb
     * @param list<string> $sharedKeys
     * @param array<int, list<array{0:int, 1:int}>> $intervals
     */
    private function extendPair(array $ta, array $tb, array $pa, array $pb, array $sharedKeys, array &$intervals, int $ia, int $ib): void
    {
        sort($sharedKeys); // determinism over hash-map order
        $seeds = $this->sample($sharedKeys, self::MAX_SEEDS_PER_PAIR);
        $ca    = count($ta);
        $cb    = count($tb);
        $best  = 0;
        $bestA = 0;
        $bestB = 0;
        foreach ($seeds as $key) {
            $budget = self::MAX_POSITION_MATCHES;
            foreach ($pa[$key] as $i) {
                foreach ($pb[$key] as $j) {
                    if (--$budget < 0) {
                        continue 2;
                    }
                    // window equality: [i, i+n) of A and [j, j+n) of B share the digest key.
                    $l = 0;
                    while (true) {
                        $la = $i - 1 - $l;
                        $lb = $j - 1 - $l;
                        if ($la < 0 || $lb < 0) {
                            break;
                        }
                        if ($ta[$la] !== $tb[$lb]) {
                            break;
                        }
                        $l++;
                    }
                    $r = 0;
                    while (true) {
                        $ra = $i + $this->n + $r;
                        $rb = $j + $this->n + $r;
                        if ($ra < 0 || $rb < 0 || $ra >= $ca || $rb >= $cb) {
                            break;
                        }
                        if ($ta[$ra] !== $tb[$rb]) {
                            break;
                        }
                        $r++;
                    }
                    $len = $l + $this->n + $r;
                    if ($len > $best) {
                        $best  = $len;
                        $bestA = $i - $l;
                        $bestB = $j - $l;
                    }
                }
            }
        }

        if ($best < self::MIN_RUN_TOKENS) {
            return;
        }
        $intervals[$ia][] = [$bestA, $bestA + $best];
        $intervals[$ib][] = [$bestB, $bestB + $best];
    }

    /**
     * Evenly-spaced sample of at most $cap entries, always including first
     * and last (a maximal run is reachable from any window inside it — the
     * sample is about spread, never about coverage of all windows).
     *
     * @param list<string> $keys
     * @return list<string>
     */
    private function sample(array $keys, int $cap): array
    {
        $c = count($keys);
        if ($c === 0) {
            return [];
        }
        if ($c <= $cap) {
            return $keys;
        }
        $out   = [];
        $step  = intdiv($c - 1, $cap - 1);
        for ($k = 0; $k < $cap; $k++) {
            $idx  = min($c - 1, $k * $step);
            $out[$idx] = $keys[$idx];
        }
        $out[$c - 1] = $keys[$c - 1];
        ksort($out);
        return array_values($out);
    }

    /**
     * Materialize one merged interval into a run, or null when the floors
     * reject it (token floor was enforced per-pair; line floor is only
     * knowable after merging).
     *
     * @param list<int> $lines
     */
    private function emit(TextFileTokens $ft, array $lines, int $start, int $end): ?TextRun
    {
        if ($end - $start < self::MIN_RUN_TOKENS) {
            return null;
        }
        $startLine = $lines[$start];
        $endLine   = $lines[$end - 1];
        if ($endLine - $startLine + 1 < self::MIN_RUN_LINES) {
            return null;
        }
        return new TextRun($ft, $start, $end, $startLine, $endLine);
    }

    /**
     * Disclosure rows for this harvest (empty in the common case).
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }
}
