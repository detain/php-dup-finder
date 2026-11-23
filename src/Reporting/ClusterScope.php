<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;

/**
 * Item 5.1 — cluster-level scope arithmetic over the repo labels
 * stamped by {@see \Phpdup\Scanning\RepoLabels}.
 *
 * Pure functions only: the cluster's own members are the single
 * source of truth, so scope is never a persisted field that could
 * drift from its evidence. A cluster is 'multi' when its members
 * carry two or more distinct non-empty repo labels — an unlabeled
 * member (synthetic block, no-match path) joins no second repo and
 * keeps the cluster 'single', the honest reading of "we do not know
 * it is cross-repo".
 *
 * The per-repo namespace grouping here is also what breaks the
 * scoring double-dip: a clone mirrored into N packages inflates the
 * raw namespace count purely because of the mirroring, not because
 * unrelated subsystems coincidentally look alike. Counting the
 * spread per repo measures the thing the penalty was always meant
 * to measure.
 *
 * output-S42 (php-dup-finder-output-059) adds a run-scoped spread
 * memo WITHOUT giving up that posture. One report run reads the
 * same member facts 3-4 times per cluster (Ranker::confidenceOf,
 * SafetyScorer, the JSON 'scope', the --scope filter); each accessor
 * used to re-walk the members. Now the first read inside a run
 * window computes the spread struct ONCE and every later read
 * serves the identical struct — the members stay the single source
 * of truth, the memo is a per-run cache of the walk, not state on
 * the cluster.
 *
 * Determinism law — the memo is RUN-SCOPED, never process-scoped:
 * {@see self::beginRun()} swaps in a fresh storage (and zeroes the
 * walk counter) at the top of every report run, and reads outside
 * any window compute fresh and attach nothing. Members are
 * re-stamped by RepoLabels::stampAll at each run's start, and
 * cluster-cache replay / Watch / Server re-enter the pipeline
 * in-process over objects that may still be alive — without the
 * per-run swap, a spread computed against the SAVING run's roots
 * could answer this run's readers. Same lifecycle contract the
 * W4-B FunctionNameRegistry law states for report-run state: fresh
 * per run(), NEVER static across runs. The walk counter and the
 * ClusterScopeTest pins hold every arm of this law honest.
 */
final class ClusterScope
{
    /** Emitted scope of a cluster whose members share one repo. */
    public const SINGLE = 'single';
    /** Emitted scope of a cluster spanning two or more distinct repos. */
    public const MULTI  = 'multi';

    /** Filter values for --scope; a fourth form is any exact repo label. */
    public const FILTER_ALL    = 'all';
    public const FILTER_SINGLE = 'single';
    public const FILTER_MULTI  = 'multi';

    /**
     * Spread memo of the open run window, keyed by cluster IDENTITY
     * (SplObjectStorage keeps its keys alive, so two clusters can
     * never alias a slot while both exist). Null outside every window:
     * pure compute path, attach nothing.
     *
     * @var \SplObjectStorage<Cluster, array{repos: list<string>, crossRepo: bool, maxSpread: int, byRepo: array<string, array<string, true>>, hasUnlabeled: bool}>|null
     */
    private static ?\SplObjectStorage $spreadMemo = null;

    /** Actual member walks (spread computes) since the window opened. */
    private static int $memberWalkCount = 0;

    private function __construct()
    {
    }

    /**
     * Open a fresh run window: swap in an empty spread memo and zero
     * the walk counter. ReportStage::run() calls this BEFORE any
     * consumer reads scope (and before the restamp that mutates
     * members), so every cluster pays at most one walk for the whole
     * run. Calling it again invalidates ALL memoized spreads — the
     * next read recomputes from the members as they are NOW, which
     * is what makes the cross-run restamp case safe.
     */
    public static function beginRun(): void
    {
        self::$spreadMemo    = new \SplObjectStorage();
        self::$memberWalkCount = 0;
    }

    /**
     * Honest observability counter: number of member walks actually
     * computed since the current run window opened (reset by
     * beginRun(); since process start when no window has been
     * opened). The memo's whole point is measurable through it:
     * once per cluster per run, never the 3-4 repeats of
     * php-dup-finder-output-059. Pinned by ClusterScopeTest arms —
     * it increments ONLY inside compute(), so a vacuous always-zero
     * or always-grow read is impossible while the pins stay green.
     */
    public static function memberWalks(): int
    {
        return self::$memberWalkCount;
    }

    /**
     * Scope word emitted for one cluster in the JSON payload.
     */
    public static function of(Cluster $c): string
    {
        return self::spread($c)['crossRepo'] ? self::MULTI : self::SINGLE;
    }

    /** True when members carry ≥ 2 distinct non-empty repo labels. */
    public static function isCrossRepo(Cluster $c): bool
    {
        return self::spread($c)['crossRepo'];
    }

    /**
     * Distinct non-empty member repo labels, sorted (stable output).
     *
     * @return list<string>
     */
    public static function repos(Cluster $c): array
    {
        return self::spread($c)['repos'];
    }

    /**
     * Namespace sets bucketed by member repo label (unlabeled members
     * share the '' bucket — they cannot evidence cross-repo spread).
     *
     * @return array<string, array<string, true>>
     */
    public static function namespacesByRepo(Cluster $c): array
    {
        return self::spread($c)['byRepo'];
    }

    /**
     * Namespace spread within the densest single repo — the
     * double-dip-free replacement for the raw namespace count when a
     * run scores per-repo or typed. A one-repo (or unlabeled) cluster
     * returns the same number the legacy raw count would, so the
     * grouped path degrades exactly.
     */
    public static function maxRepoNamespaceSpread(Cluster $c): int
    {
        return self::spread($c)['maxSpread'];
    }

    /**
     * Apply a --scope filter value to a ranked cluster list.
     *
     *   all      → untouched (the default; every existing run is this)
     *   single   → clusters whose members share one repo
     *   multi    → clusters spanning ≥ 2 distinct non-empty repos
     *   <label>  → clusters with at least one member from repo $label
     *
     * @param list<Cluster> $clusters
     * @return list<Cluster>
     */
    public static function filter(array $clusters, string $scope): array
    {
        if ($scope === self::FILTER_ALL) {
            return $clusters;
        }
        return array_values(array_filter(
            $clusters,
            static function (Cluster $c) use ($scope): bool {
                if ($scope === self::FILTER_MULTI) {
                    return self::isCrossRepo($c);
                }
                if ($scope === self::FILTER_SINGLE) {
                    return !self::isCrossRepo($c);
                }
                // output-S42: the label branch consumes the same spread
                // struct as every other reader (the old per-call foreach
                // over members was the 4th walk of the finding). The
                // legacy semantics are kept exactly: a non-empty label
                // matches when it is one of the member repos, and '' —
                // reachable only via a literal empty scope — matches
                // clusters holding at least one unlabeled member.
                if ($scope === '') {
                    return self::spread($c)['hasUnlabeled'];
                }
                return in_array($scope, self::repos($c), true);
            },
        ));
    }

    /**
     * The cluster's spread for this read: from the run-window memo
     * when one is open (compute at most once per cluster per run),
     * from a direct walk otherwise. Both paths run the SAME
     * compute(), so a memoized answer is by construction identical
     * to a fresh one.
     *
     * @return array{repos: list<string>, crossRepo: bool, maxSpread: int, byRepo: array<string, array<string, true>>, hasUnlabeled: bool}
     */
    private static function spread(Cluster $c): array
    {
        $memo = self::$spreadMemo;
        if ($memo === null) {
            return self::compute($c);
        }
        if ($memo->contains($c)) {
            return $memo[$c];
        }
        $spread       = self::compute($c);
        $memo[$c]     = $spread;
        return $spread;
    }

    /**
     * One pass over the members yields every spread fact the run
     * reads; this is the ONLY place a member walk happens, and the
     * ONLY place the walk counter increments.
     *
     * @return array{repos: list<string>, crossRepo: bool, maxSpread: int, byRepo: array<string, array<string, true>>, hasUnlabeled: bool}
     */
    private static function compute(Cluster $c): array
    {
        self::$memberWalkCount++;
        $repos        = [];
        $byRepo       = [];
        $hasUnlabeled = false;
        foreach ($c->members as $m) {
            /** @var Block $m */
            if ($m->repo !== '') {
                $repos[$m->repo] = true;
            } else {
                $hasUnlabeled = true;
            }
            $byRepo[$m->repo][$m->namespace ?? ''] = true;
        }
        $out = array_keys($repos);
        sort($out);
        $max = 0;
        foreach ($byRepo as $namespaces) {
            $max = max($max, count($namespaces));
        }
        return [
            'repos'        => $out,
            'crossRepo'    => count($out) > 1,
            'maxSpread'    => $max,
            'byRepo'       => $byRepo,
            'hasUnlabeled' => $hasUnlabeled,
        ];
    }
}
