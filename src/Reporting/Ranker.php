<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;

/**
 * Computes impact and confidence scores for each cluster, drops
 * low-impact clusters under the configured threshold, and returns
 * clusters sorted via {@see ClusterSort}.
 *
 * Default sort is `impact:desc` to preserve the original "biggest wins
 * first" behaviour. Pass any other {@see ClusterSort} (e.g. parsed
 * from `--sort=members` or `--sort=block-size:asc`) to change the
 * ordering — Ranker still scores impact + confidence first so the
 * stable tie-breakers in ClusterSort can break ties consistently.
 */
final class Ranker
{
    private readonly ClusterSort $sort;
    private readonly SafetyScorer $safetyScorer;

    public function __construct(
        private readonly int $minImpact = 20,
        ?ClusterSort $sort = null,
        private readonly float $minSafety = 0.0,
        ?SafetyScorer $safetyScorer = null,
        // Item 5.1: scoring stance for cross-repo clones. 'global'
        // (default) runs the legacy math byte-identically; 'per-repo'
        // counts namespace spread once per repo instead of globally
        // (the double-dip fix); 'typed' additionally flips the
        // cross-repo signal into a bonus (audit posture).
        private readonly string $scoreMode = Config::SCORE_MODE_GLOBAL,
    ) {
        if (!in_array($scoreMode, Config::SCORE_MODES, true)) {
            throw new \InvalidArgumentException("score_mode must be one of " . implode('|', Config::SCORE_MODES) . ", got: $scoreMode");
        }
        $this->sort         = $sort ?? new ClusterSort();
        $this->safetyScorer = $safetyScorer ?? new SafetyScorer($scoreMode);
        // No minSafety range check here: range admission is a CLI/config
        // boundary contract (output-056), not a per-call-site crash leg.
    }

    /**
     * @param list<Cluster> $clusters
     * @return list<Cluster>
     */
    public function rank(array $clusters): array
    {
        foreach ($clusters as $c) {
            $c->impact     = $this->impactOf($c);
            $c->confidence = $this->confidenceOf($c);
            $c->safety     = $this->safetyScorer->score($c);
        }
        $kept = array_values(array_filter(
            $clusters,
            fn(Cluster $c) => $c->impact >= $this->minImpact && $c->safety >= $this->minSafety,
        ));
        return $this->sort->apply($kept);
    }

    /**
     * impact ≈ how much code disappears if the abstraction is applied.
     * Conservative formula: (members - 1) × avgBlockSize - holesCost
     */
    private function impactOf(Cluster $c): int
    {
        if (!$c->members) return 0;
        $avg = (int)round($c->avgBlockSize());
        $base = max(0, ($c->size() - 1) * $avg);
        $holesPenalty = 0;
        foreach ($c->holes as $h) {
            // Subtree holes are expensive to parameterize
            if ($h->kind === 'subtree') $holesPenalty += 5;
            if ($h->kind === 'name')    $holesPenalty += 1;
        }
        return max(0, $base - $holesPenalty);
    }

    /**
     * confidence: how safe the suggested abstraction looks.
     *
     *   start at the cluster's similarity score
     *   - subtract 0.1 for each subtree hole (large variable parts)
     *   - subtract 0.05 if cluster spans more than 3 namespaces
     *     (item 5.1: under score-mode per-repo that spread is counted
     *     within the densest member repo; under typed, cross-repo
     *     clusters get the inverse +0.05 instead)
     *   - bump 0.05 if all members share the same class
     *   - clamp to [0,1]
     */
    private function confidenceOf(Cluster $c): float
    {
        $conf = $c->similarity;
        foreach ($c->holes as $h) {
            if ($h->kind === 'subtree') $conf -= 0.1;
        }
        $namespaces = [];
        $classes = [];
        foreach ($c->members as $m) {
            $namespaces[$m->namespace ?? ''] = true;
            $classes[$m->class ?? ''] = true;
        }
        if ($this->scoreMode === Config::SCORE_MODE_GLOBAL) {
            // legacy math — byte-identical to every score ever stored.
            if (count($namespaces) > 3) $conf -= 0.05;
        } elseif (ClusterScope::isCrossRepo($c) && $this->scoreMode === Config::SCORE_MODE_TYPED) {
            // Item 5.1 audit posture: a cross-repo clone is stronger
            // dedup evidence (one shared helper erases N repo-local
            // copies), so the ns-spread penalty is replaced by a bonus.
            $conf += 0.05;
        } elseif (ClusterScope::maxRepoNamespaceSpread($c) > 3) {
            // per-repo (and typed same-repo): the >3 penalty fires on
            // the namespace spread within the densest single repo, so
            // the same subsystem mirrored into N repos no longer reads
            // as N unrelated namespaces (the double-dip fix).
            $conf -= 0.05;
        }
        if (count($classes) === 1)  $conf += 0.05;
        return max(0.0, min(1.0, $conf));
    }
}
