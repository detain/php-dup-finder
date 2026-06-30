<?php
declare(strict_types=1);

namespace Phpdup\Similarity;

use PhpParser\Node;
use Phpdup\Fingerprint\ShapeletSketch;
use Phpdup\Util\AstSerializer;

/**
 * Tree edit distance using a Zhang-Shasha base with APTED-style heavy-path
 * decomposition and bounded early termination.
 *
 * Why not pure top-down with sequence-DP?
 *   The previous TreeEditDistance was a Selkow-style top-down recursion
 *   with O(n*m) sequence DP at every level. Worst-case it touches each
 *   sub-pair multiple times. This class:
 *
 *     1. linearises both trees into post-order arrays once,
 *     2. precomputes the leftmost-leaf descendant for every node,
 *     3. identifies key-roots (Zhang-Shasha) — only those start a
 *        tree-distance DP, cutting redundant work,
 *     4. picks the heavy path at each branch (APTED idea) when seeding
 *        the DP, so we explore the densest subtree first and stand a
 *        better chance of bailing early once the running cost passes
 *        the budget.
 *
 * Returns a normalised similarity in [0, 1]:
 *     sim = 1 - TED(a,b) / max(|a|, |b|)
 */
final class AptedDistance
{
    private readonly EditCostModel $costModel;
    private readonly int $scaler;

    public function __construct(?EditCostModel $costModel = null)
    {
        $this->costModel = $costModel ?? new EditCostModel(EditCostModel::MODEL_DEFAULT);
        // Quantise per-node costs to small ints so the DP can stay
        // int-only. Scaler 2 covers the semantic-model {0.5, 1, 1.5, 2}
        // exactly as {1, 2, 3, 4}.
        $this->scaler = $this->costModel->model === EditCostModel::MODEL_DEFAULT ? 1 : 2;
    }

    /**
     * @param Node $a
     * @param Node $b
     * @param float $threshold  abort as soon as the running cost passes
     *                          (1 − threshold) × max(|a|,|b|); returns 0
     */
    public function similarity(Node $a, Node $b, float $threshold = 0.0): float
    {
        // Tier-1 pre-filter: size delta. If the trees differ in size by
        // more than the threshold permits, no number of matches could
        // close the gap — skip linearisation entirely.
        $sa = AstSerializer::nodeCount($a);
        $sb = AstSerializer::nodeCount($b);
        if ($sa === 0 || $sb === 0) {
            return $sa === $sb ? 1.0 : 0.0;
        }
        $worstUpfront = max($sa, $sb);
        $sizeBudget = (int)ceil((1.0 - $threshold) * $worstUpfront);
        if (abs($sa - $sb) > $sizeBudget) {
            return 0.0;
        }

        // Tier-2 pre-filter: shapelet sketch. A 64-bit (node-type, depth)
        // histogram fingerprint; if Jaccard-by-popcount of the two
        // sketches falls below the threshold, the trees can't possibly
        // be that similar by tree-edit either. ~10 ALU ops vs O(n²) DP.
        if ($threshold > 0.0) {
            $skA = ShapeletSketch::sketch($a);
            $skB = ShapeletSketch::sketch($b);
            // Loose bound: tree-edit similarity is upper-bounded by the
            // fraction of overlapping (type,depth) buckets present in both
            // trees. A bucket overlap below threshold/2 leaves no room.
            if (ShapeletSketch::overlap($skA, $skB) < $threshold / 2.0) {
                return 0.0;
            }
        }

        $ta = self::flatten($a);
        $tb = self::flatten($b);
        $n = count($ta['labels']);
        $m = count($tb['labels']);
        if ($n === 0 || $m === 0) {
            return $n === $m ? 1.0 : 0.0;
        }

        // Worst-case weighted distance is the sum of node costs in the
        // larger tree; that's the upper bound for the normalisation step.
        $worst = $this->worstCost($n >= $m ? $ta['labels'] : $tb['labels']);
        $budget = (int)ceil((1.0 - $threshold) * $worst);

        $cost = $this->ted($ta, $tb, $budget);
        if ($cost > $budget) {
            return 0.0;
        }
        return 1.0 - ($cost / $worst);
    }

    /** @param list<string> $labels */
    private function worstCost(array $labels): int
    {
        $sum = 0;
        foreach ($labels as $label) {
            $sum += (int)round($this->costModel->cost($label) * $this->scaler);
        }
        return max(1, $sum);
    }

    /**
     * Run a Zhang-Shasha tree-edit-distance with bounded early exit.
     *
     * @param array{labels: list<string>, ll: list<int>, kr: list<int>} $T1
     * @param array{labels: list<string>, ll: list<int>, kr: list<int>} $T2
     */
    private function ted(array $T1, array $T2, int $budget): int
    {
        $L1 = $T1['labels'];   $LL1 = $T1['ll'];   $K1 = $T1['kr'];
        $L2 = $T2['labels'];   $LL2 = $T2['ll'];   $K2 = $T2['kr'];
        $n = count($L1); $m = count($L2);

        // treedist[i][j] = distance between subtree rooted at i and subtree rooted at j
        $treedist = array_fill(0, $n, array_fill(0, $m, 0));

        // For each pair of key-roots, compute forest distances; persist tree distances.
        foreach ($K1 as $i) {
            foreach ($K2 as $j) {
                $rowMin = $this->forestDp($i, $j, $LL1, $LL2, $L1, $L2, $treedist, $budget);
                if ($rowMin > $budget) {
                    return $budget + 1;
                }
            }
        }
        return $treedist[$n - 1][$m - 1];
    }

    /**
     * Forest-distance DP for the (key_root_a, key_root_b) pair.
     * Mutates $treedist in place. Returns the minimum entry written
     * to the matrix on this iteration so the caller can early-abort.
     *
     * @param list<int>             $LL1
     * @param list<int>             $LL2
     * @param list<string>          $L1
     * @param list<string>          $L2
     * @param array<int,array<int,int>> $treedist
     */
    private function forestDp(
        int $i,
        int $j,
        array $LL1,
        array $LL2,
        array $L1,
        array $L2,
        array &$treedist,
        int $budget,
    ): int {
        $iL = $LL1[$i];
        $jL = $LL2[$j];
        $rows = $i - $iL + 2;   // +2 because we need an extra "empty forest" row at index 0
        $cols = $j - $jL + 2;

        $fd = array_fill(0, $rows, array_fill(0, $cols, 0));
        // Pre-compute per-node integer costs (scaled to keep int DP).
        // The `max(0, …)` calls assert non-negativity for psalm — both
        // expressions are mathematically ≥ 0 (iL is the leftmost-leaf
        // index of $i, and $r/$c start at 1) but psalm can't prove it
        // from the loop bounds alone.
        $costA = [];
        for ($r = 1; $r < $rows; $r++) {
            $absI = max(0, $iL + $r - 1);
            $costA[$r] = (int)round($this->costModel->cost($L1[$absI]) * $this->scaler);
            $fd[$r][0] = $fd[$r - 1][0] + $costA[$r];
        }
        $costB = [];
        for ($c = 1; $c < $cols; $c++) {
            $absJ = max(0, $jL + $c - 1);
            $costB[$c] = (int)round($this->costModel->cost($L2[$absJ]) * $this->scaler);
            $fd[0][$c] = $fd[0][$c - 1] + $costB[$c];
        }

        $observedMin = PHP_INT_MAX;
        for ($r = 1; $r < $rows; $r++) {
            $abs_i = $iL + $r - 1;
            $rowMin = PHP_INT_MAX;
            for ($c = 1; $c < $cols; $c++) {
                $abs_j = $jL + $c - 1;

                $del = $fd[$r - 1][$c] + $costA[$r];
                $ins = $fd[$r][$c - 1] + $costB[$c];

                if ($LL1[$abs_i] === $iL && $LL2[$abs_j] === $jL) {
                    // Substitution cost: 0 if labels match, max(costA, costB) otherwise.
                    $subCost = $L1[$abs_i] === $L2[$abs_j] ? 0 : max($costA[$r], $costB[$c]);
                    $sub = $fd[$r - 1][$c - 1] + $subCost;
                    $best = self::min3($del, $ins, $sub);
                    $fd[$r][$c] = $best;
                    $treedist[$abs_i][$abs_j] = $best;
                } else {
                    $prevR = $LL1[$abs_i] - $iL;
                    $prevC = $LL2[$abs_j] - $jL;
                    $sub = $fd[$prevR][$prevC] + $treedist[$abs_i][$abs_j];
                    $fd[$r][$c] = self::min3($del, $ins, $sub);
                }
                if ($fd[$r][$c] < $rowMin) {
                    $rowMin = $fd[$r][$c];
                }
            }
            if ($rowMin > $budget) {
                return $rowMin;
            }
            if ($rowMin < $observedMin) {
                $observedMin = $rowMin;
            }
        }
        return $observedMin === PHP_INT_MAX ? 0 : $observedMin;
    }

    private static function min3(int $a, int $b, int $c): int
    {
        $m = $a;
        if ($b < $m) $m = $b;
        if ($c < $m) $m = $c;
        return $m;
    }

    /**
     * Linearise a node's subtree to the arrays Zhang-Shasha needs:
     *   labels[i]  — label of post-order node i
     *   ll[i]      — index of leftmost-leaf descendant of i
     *   kr         — sorted list of key-root indices
     *
     * APTED's heavy-path heuristic is applied while ordering children:
     * the heaviest (largest-subtree) child is visited last so its full
     * traversal becomes the "tail path" of its parent, which empirically
     * matches the heavy-path strategy.
     *
     * @return array{labels: list<string>, ll: list<int>, kr: list<int>}
     */
    private static function flatten(Node $root): array
    {
        $labels = [];
        $ll = [];
        $hasLeftSibling = [];

        // Visit children in heavy-path order (heaviest last) so the DP
        // hits the densest subtree first and stands a better chance of
        // exceeding the budget early on near-misses. The walk returns
        // a tuple (leftmost-leaf-index, own-post-order-index) of the
        // node it just placed.
        $walk = static function (Node $node, bool $hasLeft) use (&$walk, &$labels, &$ll, &$hasLeftSibling): array {
            $children = self::orderedChildren($node);
            $sized = [];
            foreach ($children as $child) {
                $sized[] = ['node' => $child, 'size' => AstSerializer::nodeCount($child)];
            }
            usort($sized, static fn($p, $q) => $p['size'] <=> $q['size']);

            $myLeftmost = null;
            foreach ($sized as $idx => $entry) {
                $childInfo = $walk($entry['node'], $idx > 0);
                if ($idx === 0) {
                    $myLeftmost = $childInfo['leftmost'];
                }
                // Record whether the child node itself has a left sibling.
                $hasLeftSibling[$childInfo['idx']] = $idx > 0;
            }

            $labels[] = self::labelOf($node);
            $myIdx = count($labels) - 1;
            $hasLeftSibling[$myIdx] = $hasLeft;
            $ll[$myIdx] = $myLeftmost ?? $myIdx;
            return ['leftmost' => $ll[$myIdx], 'idx' => $myIdx];
        };
        $walk($root, false); // the root has no left sibling

        $n = count($labels);
        $kr = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === $n - 1 || ($hasLeftSibling[$i] ?? false)) {
                $kr[] = $i;
            }
        }
        sort($kr);

        return ['labels' => $labels, 'll' => $ll, 'kr' => $kr];
    }

    /** @return list<Node> */
    private static function orderedChildren(Node $node): array
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

    private static function labelOf(Node $node): string
    {
        $type = AstSerializer::shortType($node);
        $scalar = AstSerializer::scalarPart($node);
        return $scalar === null ? $type : $type . '|' . $scalar;
    }
}
