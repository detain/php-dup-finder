<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Census\CallSequenceCensus;
use Phpdup\Clustering\Cluster;
use Phpdup\Util\PathCanonical;

/**
 * Item 6.4 (improve_logic.md, THEME): group the REPORTED findings into
 * themes for the summary — the letter's "outbound HTTP", "IP math",
 * "error envelopes" roll-up, built from shared callee vocabulary and
 * directory.
 *
 * The doc itself names the ceiling for this heuristic floor: "This is
 * where an LLM pass does better than heuristics; see Phase 8." — the
 * semantic naming ("outbound HTTP" as a phrase, not a token) is item
 * 8.2's job. What this class supplies is the deterministic, offline
 * scaffold 8.2 will later relabel: clusters that share WHAT THEY CALL
 * (callee tokens from the item-3.5 census, plus the pattern tags the
 * refiner already recognised) and clusters that share WHERE THEY LIVE
 * (member directories) cohere into themes, and every cluster the themes
 * do not claim surfaces as an honest residual count — never silence.
 *
 * Substrate law (§5c one-walker): this analyzer mines NOTHING new. The
 * vocabulary is a span-scoped read over the EXISTING
 * {@see CallSequenceCensus} name index (the call_sequence arm's per-file
 * callee lines — no second AST pass, no new mining stage), falling back
 * to patternTags alone when that census is unarmed, with the degradation
 * disclosed in `basis.call_census`. Directories come from member paths
 * the clusters already carry. The whole pass is a pure function of
 * (reported clusters, census snapshot, roots) computed at report time —
 * nothing persists, so no cache key binds the knob and no Block field
 * is added (the ROLLUP precedent it mirrors).
 *
 * Greedy claim, determinism law: seeds (a token or a dir appearing in
 * >= minClustersPerTheme clusters) are ranked coverage desc, seed asc —
 * the FindingId-law tie-break; vocabulary seeds claim before directory
 * seeds (vocabulary is the letter's first grouping axis); a cluster
 * joins at most one theme, the first that claims it; a theme needs
 * >= minClustersPerTheme uncovered members to exist. The claim order is
 * a total order over content, so two runs over the same corpus emit
 * byte-identical themes regardless of cluster arrival order.
 *
 * Caps bound the payload (the rollup family's "never silently cut"
 * posture): the theme list itself, and per-theme cluster-id / dir /
 * vocabulary samples, are top-N with truncation counters; the totals
 * and per-theme cluster counts always carry the FULL measured numbers.
 *
 * `lines` sums each theme cluster's union line span; clusters inside a
 * theme can still share a file, so the sum may over-count — disclosed
 * here, and the per-cluster numbers stay authoritative in their own
 * rows (this is a presentation headline, not an accounting fact).
 *
 * CLI runs pass the scan roots so every emitted directory is the
 * scan-scoped relative form ({@see PathCanonical::underRoots()}) —
 * machine-portable by construction, like the rollup rows.
 */
final class ThemeAnalyzer
{
    /** A theme (or seed) needs at least this many distinct clusters. */
    public const DEFAULT_MIN_CLUSTERS_PER_THEME = 2;

    /** Payload caps: top-N per list, truncation-counted. */
    public const TOP_THEMES    = 10;
    public const TOP_CLUSTERS  = 10;
    public const TOP_DIRS      = 5;
    public const TOP_VOCAB     = 8;

    /** Joiner between the seed and the dominant directory in a label. */
    public const LABEL_JOINER = ' › ';

    /** Seed kinds, in claim priority order (the letter's first axis leads). */
    public const KIND_VOCABULARY = 'vocabulary';
    public const KIND_DIRECTORY  = 'directory';

    /**
     * @param list<string> $relRoots scan roots (Config::$paths) for the
     *                               relative-directory form; paths outside
     *                               all roots keep their separator-folded
     *                               literal (the PathCanonical honest
     *                               fallback).
     */
    public function __construct(
        private readonly array $relRoots = [],
        public readonly int $minClustersPerTheme = self::DEFAULT_MIN_CLUSTERS_PER_THEME,
    ) {
        if ($minClustersPerTheme < 2) {
            throw new \InvalidArgumentException(
                'minClustersPerTheme must be >= 2, got ' . $minClustersPerTheme
            );
        }
    }

    /**
     * @param list<Cluster> $clusters the REPORTED findings (mainClusters —
     *                                themes group the findings a reader
     *                                sees in the summary, per the letter;
     *                                4.4-suppressed rows are appendix
     *                                items, not summary material)
     * @param CallSequenceCensus|null $census the armed call-sequence
     *                                        census, or null when
     *                                        call_sequence was unarmed —
     *                                        then vocabulary degrades to
     *                                        patternTags only (disclosed
     *                                        in the basis block)
     * @return array<string, mixed> the summary 'themes' payload
     */
    public function analyze(array $clusters, ?CallSequenceCensus $census): array
    {
        // ---- 1. per-cluster signature (vocabulary + directories) --------
        /** @var array<int, list<string>> $vocab */
        $vocab = [];
        /** @var array<int, list<string>> $dirs */
        $dirs = [];
        /** @var array<int, array<string, int>> $dirWeight dir => member blocks */
        $dirWeight = [];
        /** @var array<int, int> $lines */
        $lines = [];
        $count = count($clusters);
        for ($i = 0; $i < $count; $i++) {
            $c = $clusters[$i];
            $tokens = [];
            foreach ($c->patternTags as $tag) {
                $tokens[$tag] = true;
            }
            $dirSet = [];
            $weight = [];
            foreach ($c->members as $m) {
                if ($census !== null) {
                    foreach ($census->calleeTokensIn($m->file, $m->range->start, $m->range->end) as $tok) {
                        $tokens[$tok] = true;
                    }
                }
                $dir = self::dirLabel(PathCanonical::underRoots($m->file, $this->relRoots));
                $dirSet[$dir] = true;
                $weight[$dir] = ($weight[$dir] ?? 0) + 1;
            }
            $vocab[$i]     = array_keys($tokens);
            $dirs[$i]      = array_keys($dirSet);
            $dirWeight[$i] = $weight;
            $lines[$i]     = $c->totalLines();
        }

        // ---- 2. inverted seed index + candidate seeds -------------------
        /** @var array<string, array<int, true>> $byToken */
        $byToken = [];
        /** @var array<string, array<int, true>> $byDir */
        $byDir = [];
        for ($i = 0; $i < $count; $i++) {
            foreach ($vocab[$i] as $tok) {
                $byToken[$tok][$i] = true;
            }
            foreach ($dirs[$i] as $dir) {
                $byDir[$dir][$i] = true;
            }
        }
        // The '' label is the honest root-level dir (a file sitting
        // directly under a scan root); it groups like any other.
        $seeds = [];
        foreach ($byToken as $tok => $members) {
            if (count($members) >= $this->minClustersPerTheme) {
                $seeds[] = ['kind' => self::KIND_VOCABULARY, 'seed' => $tok, 'coverage' => count($members)];
            }
        }
        foreach ($byDir as $dir => $members) {
            if (count($members) >= $this->minClustersPerTheme) {
                $seeds[] = ['kind' => self::KIND_DIRECTORY, 'seed' => $dir, 'coverage' => count($members)];
            }
        }

        // ---- 3. deterministic greedy claim ------------------------------
        // Vocabulary seeds first (the letter's primary axis), each group
        // coverage desc then seed asc; a seed claims only still-uncovered
        // clusters, and only forms a theme when its uncovered remainder
        // still meets the minimum.
        usort($seeds, static fn(array $x, array $y): int => [
            $x['kind'] === self::KIND_VOCABULARY ? 0 : 1,
            -$x['coverage'],
            $x['seed'],
        ] <=> [
            $y['kind'] === self::KIND_VOCABULARY ? 0 : 1,
            -$y['coverage'],
            $y['seed'],
        ]);

        /** @var array<int, int> $claimed cluster index => theme ordinal */
        $claimed = [];
        /** @var list<array{kind:string, seed:string, members:list<int>}> $themes */
        $themes = [];
        foreach ($seeds as $seed) {
            $pool  = $seed['kind'] === self::KIND_VOCABULARY ? $byToken[$seed['seed']] : $byDir[$seed['seed']];
            $free  = [];
            foreach (array_keys($pool) as $idx) {
                if (!isset($claimed[$idx])) {
                    $free[] = $idx;
                }
            }
            if (count($free) < $this->minClustersPerTheme) {
                continue;
            }
            foreach ($free as $idx) {
                $claimed[$idx] = true;
            }
            $themes[] = ['kind' => $seed['kind'], 'seed' => $seed['seed'], 'members' => $free];
        }

        // ---- 4. bounded, ordered rows ------------------------------------
        $rows = [];
        foreach ($themes as $theme) {
            $rows[] = $this->themeRow($theme, $clusters, $vocab, $dirWeight, $lines);
        }
        // Theme order: measured coverage desc, kind, seed asc (the same
        // total order as the claim pass, made explicit for the payload).
        usort($rows, static fn(array $x, array $y): int => [
            -$x['clusters'],
            $x['kind'] === self::KIND_VOCABULARY ? 0 : 1,
            $x['seed'],
        ] <=> [
            -$y['clusters'],
            $y['kind'] === self::KIND_VOCABULARY ? 0 : 1,
            $y['seed'],
        ]);

        return [
            'basis' => [
                'call_census' => $census !== null,
            ],
            'totals' => [
                'clusters'           => $count,
                'themed_clusters'    => count($claimed),
                'themeless_clusters' => $count - count($claimed),
                'themes'             => count($themes),
            ],
            'themes' => array_slice($rows, 0, self::TOP_THEMES),
            'themes_truncated' => max(0, count($rows) - self::TOP_THEMES),
        ];
    }

    /**
     * One bounded theme row. Cluster ids are content-derived already
     * (Cluster::$id, §5e); the theme id joins kind and seed so a corpus
     * edit that reorders the claim pass cannot churn an unrelated row's
     * identity.
     *
     * @param array{kind:string, seed:string, members:list<int>} $theme
     * @param list<Cluster> $clusters
     * @param array<int, list<string>> $vocab
     * @param array<int, array<string, int>> $dirWeight
     * @param array<int, int> $lines
     * @return array<string, mixed>
     */
    private function themeRow(array $theme, array $clusters, array $vocab, array $dirWeight, array $lines): array
    {
        $members    = $theme['members'];
        $ids        = [];
        $weight     = [];
        $tokenCount = [];
        $linesTotal = 0;
        foreach ($members as $idx) {
            $ids[] = $clusters[$idx]->id;
            $linesTotal += $lines[$idx];
            foreach ($dirWeight[$idx] as $dir => $n) {
                $weight[$dir] = ($weight[$dir] ?? 0) + $n;
            }
            foreach ($vocab[$idx] as $tok) {
                $tokenCount[$tok] = ($tokenCount[$tok] ?? 0) + 1;
            }
        }
        sort($ids);

        // Dominant directory: most member blocks, tie-break label asc.
        $dirOrder = array_keys($weight);
        usort($dirOrder, fn(string $x, string $y): int => [-$weight[$x], $x] <=> [-$weight[$y], $y]);
        $dominant = $dirOrder[0] ?? '';

        // Shared vocabulary sample: tokens at least two members carry,
        // excluding the seed itself; count desc, token asc.
        $shared = [];
        foreach ($tokenCount as $tok => $n) {
            if ($tok === $theme['seed'] || $n < 2) {
                continue;
            }
            $shared[] = ['token' => $tok, 'n' => $n];
        }
        usort($shared, static fn(array $x, array $y): int => [-$x['n'], $x['token']] <=> [-$y['n'], $y['token']]);

        $label = $theme['seed'];
        if ($theme['kind'] === self::KIND_VOCABULARY && $dominant !== '') {
            $label .= self::LABEL_JOINER . $dominant;
        }

        return [
            'id'    => hash('xxh64', $theme['kind'] . '|' . $theme['seed']),
            'kind'  => $theme['kind'],
            'seed'  => $theme['seed'],
            'label' => $label,
            // Measured FULL counts; only the samples below are capped.
            'clusters' => count($members),
            'lines'    => $linesTotal,
            'dominant_dir' => $dominant,
            'dirs'    => array_slice($dirOrder, 0, self::TOP_DIRS),
            'dirs_truncated' => max(0, count($dirOrder) - self::TOP_DIRS),
            'vocabulary' => array_values(array_map(
                static fn(array $t): string => $t['token'],
                array_slice($shared, 0, self::TOP_VOCAB)
            )),
            'vocabulary_truncated' => max(0, count($shared) - self::TOP_VOCAB),
            'cluster_ids' => array_slice($ids, 0, self::TOP_CLUSTERS),
            'cluster_ids_truncated' => max(0, count($ids) - self::TOP_CLUSTERS),
        ];
    }

    /** Root-stripped dirname; a root-level file folds to the empty dir. */
    private static function dirLabel(string $relPath): string
    {
        $dir = dirname($relPath);
        return $dir === '.' ? '' : $dir;
    }
}
