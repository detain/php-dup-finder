<?php
declare(strict_types=1);

namespace Phpdup\Semantic;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Coarse control-flow descriptor for a block.
 *
 * Counts node types that materially shape control flow — branches,
 * loops, returns, exception edges. Two blocks with similar CFG
 * shape vectors are likely behaviourally similar even if their
 * AST shape differs (e.g. switch vs match, recursion vs iteration).
 *
 * Used by {@see \Phpdup\Similarity\BehaviouralSimilarity} as a
 * cheap proxy for true graph-edit-distance — the full CFG-edit
 * computation is research-grade and out of scope here.
 */
final class ControlFlowGraph
{
    /**
     * @return array{
     *   branches: int,
     *   loops: int,
     *   returns: int,
     *   throws: int,
     *   catches: int,
     * }
     */
    public function summarize(Node $block): array
    {
        $finder = new NodeFinder();
        $branches = 0;
        $loops    = 0;
        $returns  = 0;
        $throws   = 0;
        $catches  = 0;
        foreach ($finder->find([$block], static fn(Node $n) => true) as $n) {
            if ($n instanceof Node\Stmt\If_ || $n instanceof Node\Stmt\ElseIf_) $branches++;
            elseif ($n instanceof Node\Stmt\Switch_) $branches++;
            elseif ($n instanceof Node\Expr\Match_) $branches++;
            elseif ($n instanceof Node\Stmt\For_
                || $n instanceof Node\Stmt\Foreach_
                || $n instanceof Node\Stmt\While_
                || $n instanceof Node\Stmt\Do_
            ) $loops++;
            elseif ($n instanceof Node\Stmt\Return_) $returns++;
            elseif ($n instanceof Node\Expr\Throw_) $throws++;
            elseif ($n instanceof Node\Stmt\Catch_) $catches++;
        }
        return compact('branches', 'loops', 'returns', 'throws', 'catches');
    }
}
