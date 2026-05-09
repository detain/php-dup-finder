<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;

/**
 * Per-cluster refactoring-safety score in [0, 1].
 *
 * Combines several risk axes into a single easy-to-read number that
 * reporters can surface alongside `confidence`. Where `confidence` says
 * "the unifier is sure the members agree", `safety` says "applying the
 * suggested refactor looks low-risk".
 *
 * Recipe:
 *   start at the cluster's similarity (a [0,1] anchor)
 *
 *     - hole type safety       : ratio of typed holes to mixed holes
 *                                (mixed/unknown holes leak risk)
 *     - cross-namespace check  : penalise clusters spanning unrelated
 *                                namespaces (most refactors should
 *                                live within a single subsystem)
 *     - member-count factor    : 2 members is risky (singleton-pair),
 *                                3-8 is the sweet spot, >8 plateaus
 *     - pattern-tag bonuses    : safe patterns get +N, behavioural
 *                                patterns subtract.
 *
 * Reporters use the resulting score for filtering (`--min-safety`)
 * and rendering (a third progress bar next to similarity/confidence).
 */
final class SafetyScorer
{
    /** @var array<string, float> tag → score delta */
    private const TAG_DELTAS = [
        // structural / config-driven patterns are mechanically extract-able
        'config-driven'      =>  0.05,
        'sql-builder'        =>  0.05,
        'crud-handler'       =>  0.03,
        'validation-chain'   =>  0.02,
        // behavioural patterns are higher-risk: arms may diverge in subtle ways
        'state-machine'      => -0.10,
        'optional-segments'  => -0.05,
    ];

    public function score(Cluster $c): float
    {
        $score = $c->similarity;

        // Hole type safety: 'mixed' / null inferred types are slippery.
        $score += $this->holeTypeFactor($c);

        // Cross-namespace check: a cluster spanning many top-level
        // namespaces is more likely to be coincidentally similar.
        $namespaces = [];
        foreach ($c->members as $m) {
            $namespaces[$m->namespace ?? ''] = true;
        }
        $nsCount = count($namespaces);
        if ($nsCount === 1) {
            $score += 0.05;
        } elseif ($nsCount > 3) {
            $score -= 0.10;
        }

        // Member-count factor.
        $score += $this->memberCountFactor($c->size());

        // Pattern-tag bonuses / penalties.
        foreach ($c->patternTags as $tag) {
            if (isset(self::TAG_DELTAS[$tag])) {
                $score += self::TAG_DELTAS[$tag];
            }
        }

        return max(0.0, min(1.0, $score));
    }

    /**
     * Hole type safety: each typed hole pulls the score up a touch;
     * each 'mixed' (or otherwise unknown-typed) hole pulls it down.
     * Saturates so a cluster with many typed holes can't hit 1.0
     * purely from this axis.
     */
    private function holeTypeFactor(Cluster $c): float
    {
        if ($c->holes === []) {
            return 0.0;
        }
        $typed = 0;
        $mixed = 0;
        foreach ($c->holes as $h) {
            if ($h->inferredType === '' || $h->inferredType === 'mixed') {
                $mixed++;
            } else {
                $typed++;
            }
        }
        $total = $typed + $mixed;
        // ratio in [-0.05, +0.05] depending on typed-vs-mixed mix.
        return ((($typed - $mixed) / $total) * 0.05);
    }

    /**
     * Member count → small additive nudge.
     *   2     : -0.05  (singleton-pair, often coincidence)
     *   3-8   : +0.03  (sweet spot for confident extraction)
     *   9+    :  0.0   (plateaus — more members ≠ more confidence)
     */
    private function memberCountFactor(int $size): float
    {
        if ($size <= 2) return -0.05;
        if ($size <= 8) return 0.03;
        return 0.0;
    }
}
