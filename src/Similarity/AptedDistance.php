<?php
declare(strict_types=1);

namespace Phpdup\Similarity;

use PhpParser\Node;
use Phpdup\Fingerprint\ShapeletSketch;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\IdentifierIds;

/**
 * Tree edit distance using a Zhang-Shasha base with APTED-style heavy-path
 * decomposition and bounded early termination.
 *
 * Why not pure top-down with sequence-DP?
 *   The previous TreeEditDistance was a Selkow-style top-down recursion
 *   with O(n*m) sequence DP at every level. Worst-case it touches each
 *   sub-pair multiple times. This class:
 *
 *     1. linearises both trees into post-order arrays once, in the
 *        nodes' physical source order (siblings keep their statement
 *        sequence — see flatten()),
 *     2. precomputes the leftmost-leaf descendant for every node,
 *     3. identifies key-roots (Zhang-Shasha) — only those start a
 *        tree-distance DP, cutting redundant work,
 *     4. bounds the DP with an early-exit budget so that pairs too
 *        far apart to clear the similarity threshold stop filling
 *        cells once their running minimum passes it.
 *
 *     A legitimate APTED heavy-path optimisation reorders only the
 *     key-root TRAVERSAL order, never the serialized label sequence:
 *     Zhang-Shasha models ORDERED trees, so the sibling sequence is the
 *     statement sequence and permuting it silently erases source order
 *     (engine-007). We therefore keep physical order throughout.
 *
 * Per-pair inputs are memoised for the lifetime of this instance, keyed
 * by object identity of the node seen last (engine-009): the Clusterer
 * scores a k-member component as ~k²/2 pairs and every pair re-presented
 * the same two immutable canonical trees, so nodeCount/sketch/flatten/
 * worstCost were recomputed once per pair. The memo is process-local and
 * holds a strong reference to each keyed node, so an `spl_object_id`
 * can never be recycled under it while its entry is alive.
 *
 * The memo caches INPUTS only — it can never answer a pair. Every entry
 * path, cold or cache-warm, runs the same budget-band gate before the
 * tuple/sketch memos can serve anything (engine-S09 r1, MEDIUM-1: one
 * gate for both paths, so an over-budget pair is refused identically
 * whether or not its inputs were already memoised).
 *
 * The DP itself is budget-banded (engine-009, second half): cells whose
 * positional lower bound |r − c| already exceeds the budget cannot lie
 * on an accepting alignment, so they are never computed and read back as
 * the saturation value. Every cell is held in the capped domain
 * min(true, budget + 1), which is exact for every result the class
 * reports: a pair accepted above threshold carries its true cost
 * unchanged. A band-exceeding pair is never reported as a laundered
 * truncated number — ted() hands back the explicit OVER_BUDGET sentinel
 * and similarity() refuses it with the same constant the gates use
 * (engine-S09 r1, MEDIUM-2: explicit refusal, never silent truncation,
 * matching the engine-S01 df-cap house style).
 *
 * Returns a normalised similarity in [0, 1]:
 *     sim = 1 - TED(a,b) / max(|a|, |b|)
 */
final class AptedDistance
{
    /**
     * The one refusal value this class reports. Both refusal paths — the
     * over-budget gates and the band-exceeding DP (via OVER_BUDGET) —
     * return it, so a cold computation and a memo-warm lookup can never
     * disagree on a rejected pair (engine-S09 r1, MEDIUM-1/MEDIUM-2).
     */
    public const REFUSE = 0.0;

    /**
     * Sentinel returned by ted() when the true edit distance exceeds the
     * budget band. Engine-S09 r1 (MEDIUM-2) made this explicit: it lives
     * in the negative domain, strictly below every legitimate cost and
     * every reachable saturation value (min budget = 0 → cap ≥ 1), so it
     * can never be mistaken for — or arithmetically laundered into — a
     * distance. similarity() maps it to REFUSE.
     */
    private const OVER_BUDGET = -1;

    private readonly EditCostModel $costModel;
    private readonly int $scaler;
    private readonly bool $unnumbered;

    // engine-S09 r1 (LOW-3): hit-vs-cold and gate-vs-band refusals must
    // be observable, or the shared-gate pins (MEDIUM-1/MEDIUM-2) cannot
    // be written non-vacuously. Plain additive counters, process-local
    // like every memo; see diagnostics().
    /** @var int DP-input tuple memo lookups served from a cached entry */
    private int $tupleMemoHits = 0;
    /** @var int DP-input tuple memo lookups that computed a fresh entry */
    private int $tupleMemoCold = 0;
    /** @var int pairs refused by a pre-DP gate (size delta / sketch bound) */
    private int $gateRefusals = 0;
    /** @var int pairs refused because their true distance exceeded the budget band */
    private int $bandRefusals = 0;

    /** @var \SplObjectStorage<Node, int> post-order node count per tree root */
    private \SplObjectStorage $countMemo;

    /** @var \SplObjectStorage<Node, int> 64-bit shapelet sketch per tree root */
    private \SplObjectStorage $sketchMemo;

    /** @var \SplObjectStorage<Node, array{labels: list<string>, ll: list<int>, kr: list<int>, costs: list<int>, worst: int}> */
    private \SplObjectStorage $tupleMemo;

    public function __construct(?EditCostModel $costModel = null, bool $unnumbered = false)
    {
        $this->costModel = $costModel ?? new EditCostModel(EditCostModel::MODEL_DEFAULT);
        // Quantise per-node costs to small ints so the DP can stay
        // int-only. Scaler 2 covers the semantic-model {0.5, 1, 1.5, 2}
        // exactly as {1, 2, 3, 4}.
        $this->scaler = $this->costModel->model === EditCostModel::MODEL_DEFAULT ? 1 : 2;
        // Item 1.1 (§5a): un-number the `__V1`-style labels too, in
        // lockstep with NgramFingerprint's bag mode. Coarser labels can
        // only lower the edit cost (TED similarity is monotone ≥ the
        // numbered score), so every J-tier transition is an
        // improvement. false = byte-identical legacy behaviour.
        $this->unnumbered = $unnumbered;
        $this->countMemo  = new \SplObjectStorage();
        $this->sketchMemo = new \SplObjectStorage();
        $this->tupleMemo  = new \SplObjectStorage();
    }

    /**
     * Test/profiling probe: has this instance already memoised the DP
     * inputs (flatten tuple) for the given tree root? engine-009's
     * acceptance pins the object-identity caching through this.
     */
    public function hasMemoisedInputs(Node $node): bool
    {
        return $this->tupleMemo->contains($node);
    }

    /**
     * engine-S09 r1 (LOW-3): make the memo paths and the two refusal
     * sources observable. "hits" counts tuple-memo lookups served from a
     * cached entry, "cold" counts lookups that computed a fresh entry;
     * "gate" counts pre-DP refusals (size delta / sketch bound),
     * "band" counts OVER_BUDGET refusals from the banded DP. A pin that
     * must prove a cache-hit pair went through the SAME gate as a cold
     * one reads these instead of trusting a symmetric 0.0 == 0.0.
     *
     * @return array{hits: int, cold: int, gate: int, band: int}
     */
    public function diagnostics(): array
    {
        return [
            'hits' => $this->tupleMemoHits,
            'cold' => $this->tupleMemoCold,
            'gate' => $this->gateRefusals,
            'band' => $this->bandRefusals,
        ];
    }

    /**
     * @param Node $a
     * @param Node $b
     * @param float $threshold  abort as soon as the running cost passes
     *                          (1 − threshold) × max(|a|,|b|); returns 0
     */
    public function similarity(Node $a, Node $b, float $threshold = 0.0): float
    {
        // The ONE budget-band gate (engine-S09 r1, MEDIUM-1): evaluated at
        // the top of EVERY entry path — cold or memo-warm — before the
        // tuple/sketch memos can serve a pair. A pair of over-budget
        // trees (size delta beyond what the threshold permits) is refused
        // here and never reaches the DP inputs, so the cache can never
        // answer a pair the cold path refuses, nor vice-versa. The sizes
        // ride countOf()'s input cache (engine-009): a memo count equals
        // a fresh walk by construction — the trees are immutable while
        // referenced — and testRefusalGateServesCacheHitPairsIdentically-
        // ToCold pins gate-equality through the diagnostics seam.
        $sa = $this->countOf($a);
        $sb = $this->countOf($b);
        if ($sa === 0 || $sb === 0) {
            return $sa === $sb ? 1.0 : self::REFUSE;
        }
        $worstUpfront = max($sa, $sb);
        $sizeBudget = (int)ceil((1.0 - $threshold) * $worstUpfront);
        if (abs($sa - $sb) > $sizeBudget) {
            $this->gateRefusals++;
            return self::REFUSE;
        }

        // Tier-2 pre-filter: shapelet sketch. A 64-bit (node-type, depth)
        // histogram fingerprint; if Jaccard-by-popcount of the two
        // sketches falls below the threshold, the trees can't possibly
        // be that similar by tree-edit either. ~10 ALU ops vs O(n²) DP.
        // A sketch-gate refusal likewise never touches the tuple memo.
        if ($threshold > 0.0) {
            $skA = $this->sketchOf($a);
            $skB = $this->sketchOf($b);
            // Loose bound: tree-edit similarity is upper-bounded by the
            // fraction of overlapping (type,depth) buckets present in both
            // trees. A bucket overlap below threshold/2 leaves no room.
            if (ShapeletSketch::overlap($skA, $skB) < $threshold / 2.0) {
                $this->gateRefusals++;
                return self::REFUSE;
            }
        }

        $ta = $this->tupleOf($a);
        $tb = $this->tupleOf($b);
        $n = count($ta['labels']);
        $m = count($tb['labels']);
        if ($n === 0 || $m === 0) {
            return $n === $m ? 1.0 : self::REFUSE;
        }

        // Worst-case weighted distance is the sum of node costs in the
        // larger tree; that's the upper bound for the normalisation step.
        $worst = $n >= $m ? $ta['worst'] : $tb['worst'];
        $budget = (int)ceil((1.0 - $threshold) * $worst);

        $cost = $this->ted($ta, $tb, $budget);
        if ($cost === self::OVER_BUDGET) {
            // MEDIUM-2: the band truncated the computation — the true
            // distance exceeds the budget. Report the explicit refusal,
            // never a normalisation of the saturation value.
            $this->bandRefusals++;
            return self::REFUSE;
        }
        return 1.0 - ($cost / $worst);
    }

    /**
     * Memoised post-order node count (engine-009). The canonical trees
     * the Clusterer hands us are immutable for the run, so the walk runs
     * once per distinct root object instead of once per candidate pair.
     */
    private function countOf(Node $node): int
    {
        if ($this->countMemo->contains($node)) {
            return $this->countMemo[$node];
        }
        $count = AstSerializer::nodeCount($node);
        $this->countMemo[$node] = $count;
        return $count;
    }

    /** Memoised shapelet sketch (engine-009); see countOf() for the immutability contract. */
    private function sketchOf(Node $node): int
    {
        if ($this->sketchMemo->contains($node)) {
            return $this->sketchMemo[$node];
        }
        $sketch = ShapeletSketch::sketch($node);
        $this->sketchMemo[$node] = $sketch;
        return $sketch;
    }

    /**
     * Memoised per-tree DP inputs (engine-009): the flatten tuple plus
     * the scaled per-label insert/delete costs and their sum (worstCost).
     * Cost quantisation depends on this instance's cost model and the
     * label stream on its `unnumbered` mode, which is exactly why the
     * memo lives on the instance rather than in a shared table.
     *
     * @return array{labels: list<string>, ll: list<int>, kr: list<int>, costs: list<int>, worst: int}
     */
    private function tupleOf(Node $node): array
    {
        if ($this->tupleMemo->contains($node)) {
            $this->tupleMemoHits++;
            return $this->tupleMemo[$node];
        }
        $this->tupleMemoCold++;
        $tuple = $this->flatten($node);
        $costs = [];
        $sum = 0;
        foreach ($tuple['labels'] as $label) {
            $cost = (int)round($this->costModel->cost($label) * $this->scaler);
            $costs[] = $cost;
            $sum += $cost;
        }
        $tuple['costs'] = $costs;
        $tuple['worst'] = max(1, $sum);
        $this->tupleMemo[$node] = $tuple;
        return $tuple;
    }

    /**
     * Run a Zhang-Shasha tree-edit-distance with bounded early exit.
     *
     * Budget early-exit is applied at this level: after each forestDp()
     * call returns the minimum cell value of the completed submatrix, if
     * that minimum exceeds the budget we return the OVER_BUDGET sentinel
     * immediately (engine-S09 r1, MEDIUM-2: an explicit refusal, not the
     * saturation value laundered through the acceptance comparison).
     * This ensures all treedist band cells for the current key-root pair
     * are written before the abort decision is made.
     *
     * The grid is allocated lazily (engine-009): the old
     * `array_fill(0, $n, array_fill(0, $m, 0))` paid a full n·m hash
     * table on every pair — ~0.55M entries at the 800-node gate size the
     * Clusterer admits — for cells the band never touches. A missing
     * cell now reads as the saturation value $cap, which is exactly its
     * capped true value (unwritten cells are out-of-band by construction
     * and therefore exceed the budget).
     *
     * @param array{labels: list<string>, ll: list<int>, kr: list<int>, costs: list<int>, worst: int} $T1
     * @param array{labels: list<string>, ll: list<int>, kr: list<int>, costs: list<int>, worst: int} $T2
     */
    private function ted(array $T1, array $T2, int $budget): int
    {
        $n = count($T1['labels']); $m = count($T2['labels']);
        $cap = $budget + 1;

        // treedist[i][j] = distance between subtree rooted at i and subtree rooted at j;
        // a null row means "no cell of this row was ever in band", i.e. reads are $cap.
        /** @var array<int,array<int,int>|null> $treedist */
        $treedist = [];

        // For each pair of key-roots, compute forest distances; persist tree distances.
        foreach ($T1['kr'] as $i) {
            foreach ($T2['kr'] as $j) {
                $rowMin = $this->forestDp($i, $j, $T1, $T2, $treedist, $budget, $cap);
                if ($rowMin > $budget) {
                    // MEDIUM-2: the true distance exceeded the band — hand
                    // back the explicit refusal sentinel, never $cap (which
                    // is a capped DISTANCE value and must not travel the
                    // acceptance arithmetic).
                    return self::OVER_BUDGET;
                }
            }
        }
        $final = $treedist[$n - 1][$m - 1] ?? $cap;
        return $final > $budget ? self::OVER_BUDGET : $final;
    }

    /**
     * Forest-distance DP for the (key_root_a, key_root_b) pair.
     * Mutates $treedist in place. Returns the minimum entry written
     * to the matrix on this iteration so the caller can early-abort.
     *
     * CAPPED DOMAIN (engine-009): every cell holds min(true, $cap).
     * Each insert/delete costs at least one scaled unit, so a cell can
     * never sit below the positional lower bound |r − c| (triangle
     * argument over the alignment path, using TED(x, y) ≥ |size(x) −
     * size(y)| for the subtree jumps). Cells outside the band
     * |r − c| ≤ budget therefore exceed the budget and are never
     * computed — they sit in the row's saturation fill and read back as
     * $cap, which is their exact capped value. Every accepting alignment
     * (final cost ≤ budget) lies wholly inside the band, so reported
     * distances stay exact and only rejected pairs saturate — and those
     * report 0.0 similarity either way.
     *
     * CRITICAL INVARIANT: all band cells of the treedist submatrix for
     * this key-root pair (i.e., all in-band rows from $iL to $i and all
     * in-band columns from $jL to $j) are unconditionally written before
     * this method returns, regardless of whether the budget is exceeded.
     * This prevents stale cells from poisoning later key-root
     * computations via the Zhang-Shasha recurrence below. Early-abort
     * decision is made ONLY at the ted() level, after this method
     * returns.
     *
     * @param array{labels: list<string>, ll: list<int>, kr: list<int>, costs: list<int>, worst: int} $T1
     * @param array{labels: list<string>, ll: list<int>, kr: list<int>, costs: list<int>, worst: int} $T2
     * @param array<int,array<int,int>|null> $treedist lazily materialised rows
     */
    private function forestDp(
        int $i,
        int $j,
        array $T1,
        array $T2,
        array &$treedist,
        int $budget,
        int $cap,
    ): int {
        $LL1 = $T1['ll'];       $L1 = $T1['labels'];  $C1 = $T1['costs'];
        $LL2 = $T2['ll'];       $L2 = $T2['labels'];  $C2 = $T2['costs'];
        $iL = $LL1[$i];
        $jL = $LL2[$j];
        $rows = $i - $iL + 2;   // +2 because we need an extra "empty forest" row at index 0
        $cols = $j - $jL + 2;
        $lastCol = $cols - 1;
        $m2 = count($L2);

        // Dense, saturated rows: out-of-band cells already hold $cap, so
        // the hot loop reads every neighbour plainly instead of probing
        // with `??` per cell. The base row/column are the running
        // insert/delete costs, saturated like every other cell (they obey
        // fd[r][0] ≥ r = |r − 0|, so the capped representation is
        // consistent with the band bound). The `max(0, …)` calls assert
        // non-negativity for psalm — both expressions are mathematically
        // ≥ 0 (iL is the leftmost-leaf index of $i, and $r/$c start at 1)
        // but psalm can't prove it from the loop bounds alone.
        $fd = array_fill(0, $rows, array_fill(0, $cols, $cap));
        $fd[0][0] = 0;
        // Windowed copies of the memoised costs, indexed by the local
        // (positive) row/column so the inner loop never offsets by a
        // derived index — same shape the pre-engine-009 loop used, only
        // without re-quantising labels (that is what tupleOf() caches).
        $costA = [];
        $run = 0;
        for ($r = 1; $r < $rows; $r++) {
            $absI = max(0, $iL + $r - 1);
            $costA[$r] = $C1[$absI];
            $run += $costA[$r];
            if ($run > $cap) {
                $run = $cap;
            }
            $fd[$r][0] = $run;
        }
        $costB = [];
        $run = 0;
        for ($c = 1; $c < $cols; $c++) {
            $absJ = max(0, $jL + $c - 1);
            $costB[$c] = $C2[$absJ];
            $run += $costB[$c];
            if ($run > $cap) {
                $run = $cap;
            }
            $fd[0][$c] = $run;
        }

        // A row whose whole band is empty holds only cells with
        // |r − c| > budget, i.e. values ≥ $cap; starting each row's
        // running minimum at $cap reproduces the old exact-domain min()
        // without visiting those cells.
        $observedMin = $cap;
        for ($r = 1; $r < $rows; $r++) {
            $abs_i = $iL + $r - 1;
            $costA_r = $costA[$r];
            $cLo = $r - $budget;
            if ($cLo < 1) {
                $cLo = 1;
            }
            $cHi = $r + $budget;
            if ($cHi > $lastCol) {
                $cHi = $lastCol;
            }

            $rowRef =& $fd[$r];
            $above = $fd[$r - 1];
            // engine-009 band: only |r − c| ≤ budget can host a cell at or
            // under budget; everything else is already known > budget.
            $rowAligned = $LL1[$abs_i] === $iL;
            $prevR = $rowAligned ? 0 : $LL1[$abs_i] - $iL;
            $tdRow =& $treedist[$abs_i];   // null until this row's first write
            $rowMin = $cap;
            for ($c = $cLo; $c <= $cHi; $c++) {
                $abs_j = $jL + $c - 1;
                $costB_c = $costB[$c];

                $del = $above[$c] + $costA_r;
                $ins = $rowRef[$c - 1] + $costB_c;

                if ($rowAligned && $LL2[$abs_j] === $jL) {
                    // Substitution cost: 0 if labels match, max(costA, costB) otherwise.
                    $subCost = $L1[$abs_i] === $L2[$abs_j] ? 0 : ($costA_r > $costB_c ? $costA_r : $costB_c);
                    $sub = $above[$c - 1] + $subCost;
                    $best = self::min3($del, $ins, $sub);
                    if ($best > $cap) {
                        $best = $cap;
                    }
                    $rowRef[$c] = $best;
                    if ($tdRow === null) {
                        $tdRow = array_fill(0, $m2, $cap);
                    }
                    $tdRow[$abs_j] = $best;
                } else {
                    $sub = $fd[$prevR][$LL2[$abs_j] - $jL] + ($tdRow === null ? $cap : $tdRow[$abs_j]);
                    $best = self::min3($del, $ins, $sub);
                    if ($best > $cap) {
                        $best = $cap;
                    }
                    $rowRef[$c] = $best;
                }
                if ($best < $rowMin) {
                    $rowMin = $best;
                }
            }
            unset($rowRef, $tdRow);
            if ($rowMin < $observedMin) {
                $observedMin = $rowMin;
            }
        }
        return $observedMin;
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
     * Children are emitted in their physical source order. The sibling
     * sequence is the statement sequence, so reordering it (an earlier
     * size-sort) permuted the comparison model and made statement-
     * permuted clones score edit distance 0 (engine-007). Any heavy-
     * path-style speedup belongs to key-root traversal order only, not
     * to this serialized sequence.
     *
     * @return array{labels: list<string>, ll: list<int>, kr: list<int>}
     */
    private function flatten(Node $root): array
    {
        $labels = [];
        $ll = [];
        $hasLeftSibling = [];
        $unnumbered = $this->unnumbered;

        // Visit children in physical source order; the walk returns a
        // tuple (leftmost-leaf-index, own-post-order-index) of the node
        // it just placed.
        $walk = static function (Node $node, bool $hasLeft) use (&$walk, &$labels, &$ll, &$hasLeftSibling, $unnumbered): array {
            $children = self::orderedChildren($node);

            $myLeftmost = null;
            foreach ($children as $idx => $child) {
                $childInfo = $walk($child, $idx > 0);
                if ($idx === 0) {
                    $myLeftmost = $childInfo['leftmost'];
                }
                // Record whether the child node itself has a left sibling.
                $hasLeftSibling[$childInfo['idx']] = $idx > 0;
            }

            $labels[] = self::labelOf($node, $unnumbered);
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

    private static function labelOf(Node $node, bool $unnumbered = false): string
    {
        $type = AstSerializer::shortType($node);
        $scalar = AstSerializer::scalarPart($node);
        $label = $scalar === null ? $type : $type . '|' . $scalar;
        // Engine-S02a: name stamps fold in both modes (similarity tiers
        // are vocabulary-agnostic by aggressive mode's contract); the
        // first-occurrence counters stay behind the item-1.1 knob, in
        // lockstep with NgramFingerprint via this shared helper.
        $label = IdentifierIds::foldStamps($label);
        return $unnumbered ? IdentifierIds::strip($label) : $label;
    }
}
