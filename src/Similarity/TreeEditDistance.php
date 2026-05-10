<?php
declare(strict_types=1);

namespace Phpdup\Similarity;

use PhpParser\Node;

/**
 * Tree edit distance facade — delegates to {@see AptedDistance}.
 *
 * Historically this class held an inline top-down Selkow-style
 * implementation; it has been reimplemented as a Zhang-Shasha base with
 * APTED-style heavy-path ordering and bounded early termination, and
 * lives in {@see AptedDistance}. This class remains for API stability
 * and as the single point at which we could swap in alternative
 * distance metrics.
 */
final class TreeEditDistance
{
    private AptedDistance $apted;

    public function __construct(?EditCostModel $costModel = null)
    {
        $this->apted = new AptedDistance($costModel);
    }

    public function similarity(Node $a, Node $b, float $threshold = 0.0): float
    {
        return $this->apted->similarity($a, $b, $threshold);
    }
}
