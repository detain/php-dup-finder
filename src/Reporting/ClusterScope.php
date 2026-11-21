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

    private function __construct()
    {
    }

    /**
     * Scope word emitted for one cluster in the JSON payload.
     */
    public static function of(Cluster $c): string
    {
        return self::isCrossRepo($c) ? self::MULTI : self::SINGLE;
    }

    /** True when members carry ≥ 2 distinct non-empty repo labels. */
    public static function isCrossRepo(Cluster $c): bool
    {
        return count(self::repos($c)) > 1;
    }

    /**
     * Distinct non-empty member repo labels, sorted (stable output).
     *
     * @return list<string>
     */
    public static function repos(Cluster $c): array
    {
        $repos = [];
        foreach ($c->members as $m) {
            /** @var Block $m */
            if ($m->repo !== '') {
                $repos[$m->repo] = true;
            }
        }
        $out = array_keys($repos);
        sort($out);
        return $out;
    }

    /**
     * Namespace sets bucketed by member repo label (unlabeled members
     * share the '' bucket — they cannot evidence cross-repo spread).
     *
     * @return array<string, array<string, true>>
     */
    public static function namespacesByRepo(Cluster $c): array
    {
        $byRepo = [];
        foreach ($c->members as $m) {
            /** @var Block $m */
            $byRepo[$m->repo][$m->namespace ?? ''] = true;
        }
        return $byRepo;
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
        $max = 0;
        foreach (self::namespacesByRepo($c) as $namespaces) {
            $max = max($max, count($namespaces));
        }
        return $max;
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
                foreach ($c->members as $m) {
                    /** @var Block $m */
                    if ($m->repo === $scope) {
                        return true;
                    }
                }
                return false;
            },
        ));
    }
}
