<?php
declare(strict_types=1);

namespace Phpdup\Similarity;

use PhpParser\Node;
use Phpdup\Util\AstSerializer;

/**
 * Bounded top-down tree edit distance for canonical ASTs.
 *
 * Computes a label-aware edit distance between two AST subtrees:
 *
 *   - if root labels differ, the cost is size(a)+size(b) (delete A,
 *     insert B);
 *   - otherwise cost is 1 (match the root) plus the optimal
 *     sequence alignment between the two child sequences, where
 *     each pair contributes recursive ted(child_i, child_j),
 *     unmatched children contribute their size.
 *
 * The recursion is bounded by a budget: as soon as the running cost
 * exceeds the budget, we stop and return budget+1. Most pairs reject
 * in O(1) work this way after Jaccard pre-filtering.
 *
 * This is APTED-style top-down decomposition rather than full
 * Zhang-Shasha; for *canonical* ASTs the top-down formulation is a
 * tight approximation and considerably faster.
 */
final class TreeEditDistance
{
    /** @var \WeakMap<Node,int> */
    private \WeakMap $sizeCache;

    public function __construct()
    {
        $this->sizeCache = new \WeakMap();
    }

    /**
     * Returns a normalized similarity in [0,1]:
     *
     *   sim = 1 - ted(a,b) / max(size(a), size(b))
     *
     * Returns 0 if cost exceeds budget.
     */
    public function similarity(Node $a, Node $b, float $threshold = 0.0): float
    {
        $sa = $this->sizeOf($a);
        $sb = $this->sizeOf($b);
        $worst = max($sa, $sb);
        if ($worst === 0) {
            return 1.0;
        }
        $maxAcceptable = (int)ceil((1.0 - $threshold) * $worst);
        $cost = $this->ted($a, $b, $maxAcceptable);
        if ($cost > $maxAcceptable) {
            return 0.0;
        }
        return 1.0 - ($cost / $worst);
    }

    private function ted(Node $a, Node $b, int $budget): int
    {
        if ($budget < 0) {
            return PHP_INT_MAX;
        }
        $la = AstSerializer::shortType($a) . '|' . (AstSerializer::scalarPart($a) ?? '');
        $lb = AstSerializer::shortType($b) . '|' . (AstSerializer::scalarPart($b) ?? '');

        if ($la !== $lb) {
            $cost = $this->sizeOf($a) + $this->sizeOf($b);
            return min($cost, $budget + 1);
        }

        $childrenA = $this->childrenOf($a);
        $childrenB = $this->childrenOf($b);
        if (!$childrenA && !$childrenB) {
            return 1;
        }

        // sequence alignment DP
        $m = count($childrenA);
        $n = count($childrenB);
        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
        for ($i = 1; $i <= $m; $i++) {
            $dp[$i][0] = $dp[$i - 1][0] + $this->sizeOf($childrenA[$i - 1]);
        }
        for ($j = 1; $j <= $n; $j++) {
            $dp[0][$j] = $dp[0][$j - 1] + $this->sizeOf($childrenB[$j - 1]);
        }
        $remaining = $budget - 1;
        for ($i = 1; $i <= $m; $i++) {
            $rowMin = PHP_INT_MAX;
            for ($j = 1; $j <= $n; $j++) {
                $del = $dp[$i - 1][$j] + $this->sizeOf($childrenA[$i - 1]);
                $ins = $dp[$i][$j - 1] + $this->sizeOf($childrenB[$j - 1]);
                $match = $dp[$i - 1][$j - 1] + $this->ted($childrenA[$i - 1], $childrenB[$j - 1], $remaining - $dp[$i - 1][$j - 1]);
                $best = min($del, $ins, $match);
                $dp[$i][$j] = $best;
                if ($best < $rowMin) {
                    $rowMin = $best;
                }
            }
            if ($rowMin > $remaining) {
                return $budget + 1; // entire row exceeded — abort
            }
        }
        return 1 + $dp[$m][$n];
    }

    /** @return list<Node> */
    private function childrenOf(Node $node): array
    {
        $children = [];
        foreach ($node->getSubNodeNames() as $sub) {
            $val = $node->$sub;
            if ($val instanceof Node) {
                $children[] = $val;
            } elseif (is_array($val)) {
                foreach ($val as $v) {
                    if ($v instanceof Node) {
                        $children[] = $v;
                    }
                }
            }
        }
        return $children;
    }

    private function sizeOf(Node $node): int
    {
        if (isset($this->sizeCache[$node])) {
            return $this->sizeCache[$node];
        }
        $size = AstSerializer::nodeCount($node);
        $this->sizeCache[$node] = $size;
        return $size;
    }
}
