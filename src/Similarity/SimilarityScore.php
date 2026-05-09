<?php
declare(strict_types=1);

namespace Phpdup\Similarity;

/**
 * Combined similarity score with a confidence component.
 *
 * Reported similarity is the lower of the two signals (Jaccard and
 * tree-edit similarity) so we don't claim more agreement than both
 * methods support. Confidence is the gap between them — close
 * agreement = high confidence, divergence = low.
 */
final class SimilarityScore
{
    public function __construct(
        public readonly float $jaccard,
        public readonly float $treeEdit,
    ) {
    }

    public function combined(): float
    {
        return min($this->jaccard, $this->treeEdit);
    }

    public function confidence(): float
    {
        return 1.0 - abs($this->jaccard - $this->treeEdit);
    }
}
