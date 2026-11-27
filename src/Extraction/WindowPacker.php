<?php
declare(strict_types=1);

namespace Phpdup\Extraction;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use Phpdup\Util\LineRange;

/**
 * Item 1.2 — the scan-window machinery oversized blocks are cut with.
 *
 * A window is a contiguous run of statements from ONE oversized body,
 * wrapped in a synthetic Stmt\Block so it carries a real Node like every
 * other block. It is synthetic in the exact sense the 1.3 'script' fold
 * is (see ScriptKindApplyRefusal's docblock for that law): the wrapper
 * has no parse-tree position, so windows mint the dedicated
 * {@see BlockExtractor::WINDOW_KIND} ('window'), keep their AST for the
 * whole run (both lazy-unload sites exempt them), and are refused by
 * every AST-addressing surface ({@see \Phpdup\Refactor\WindowApplyRefusal}).
 *
 * Lives beside its only two callers (BlockExtractor's visitor oversized
 * leg and its oversized script-fold leg) as @internal: not user-facing
 * API, and private cross-class statics are not callable in PHP, so a
 * shared home is the honest shape.
 */
final class WindowPacker
{
    private function __construct()
    {
    }

    /**
     * The statement list a window can be cut from, or null when the node
     * has none. Windowable = plain `stmts` bodies: function / method /
     * closure declarations and the control-structure bodies (if/for/
     * foreach/while/do/try). Not windowable: arrow functions (single
     * expression), switch (case lists) and match (arm lists) — those keep
     * the historic drop, now counted (item 0.6). An abstract method
     * (`ClassMethod::$stmts === null`) has no body to window either.
     *
     * v1 stance: an oversized `if` offers only its THEN body — elseif
     * chains and else arms stay invisible (their inner kind nodes still
     * descend into the normal traversal, so any kind inside them
     * extracts as usual). Disclosed residual, like the lone-giant-
     * statement residual below.
     *
     * @return ?list<Node\Stmt>
     */
    public static function windowableBody(Node $node): ?array
    {
        if (
            $node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod
            || $node instanceof Node\Expr\Closure
            || $node instanceof Stmt\If_ || $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_ || $node instanceof Stmt\TryCatch
        ) {
            /** @var list<Node\Stmt>|null $stmts */
            $stmts = $node->stmts;
            return $stmts;
        }
        return null;
    }

    /**
     * Greedy contiguous statement-run packer — one code path, one
     * posture, shared by the visitor's oversized-declaration leg and the
     * oversized script-fold leg. Runs accumulate whole statements in
     * source order while the Σ subtree count fits under (maxSize − 1),
     * reserving the wrapper's own node; each run is then wrapped in a
     * synthetic Stmt\Block and its REAL nodeCount re-checked (shared-node
     * artifacts can inflate a wrapper beyond Σ), popping the tail
     * statement until it fits or the run empties. Windows obey the
     * minSize floor unconditionally: a fragment is not a declaration
     * (that exemption belongs to whole constructs, not cuts of them). A
     * single statement already larger than the cap cannot start or join
     * any window — its mass stays invisible to v1 windowing (disclosed
     * residual, sibling of the pre-1.2 drop).
     *
     * Deterministic: pure source-order walk, fixed order.
     *
     * @param list<Node\Stmt>       $stmts
     * @param \Closure(Node):int    $count
     * @param \Closure(Block):void  $sink
     * @return int number of window blocks emitted (0 = nothing salvaged)
     */
    public static function emitWindowRuns(
        string $file,
        ?string $namespace,
        ?string $class,
        ?string $name,
        array $stmts,
        int $minSize,
        int $maxSize,
        \Closure $count,
        \Closure $sink,
    ): int {
        /** @var list<list<Node\Stmt>> $runs */
        $runs = [];
        $run = [];
        $sum = 0;
        foreach ($stmts as $s) {
            $c = $count($s);
            if ($c > $maxSize - 1) {
                continue; // lone giant statement: no window can host it
            }
            if ($sum + $c > $maxSize - 1) {
                $runs[] = $run;
                $run = [];
                $sum = 0;
            }
            $run[] = $s;
            $sum += $c;
        }
        if ($run !== []) {
            $runs[] = $run;
        }

        $emitted = 0;
        foreach ($runs as $run) {
            while ($run !== []) {
                $first = $run[0];
                $last  = $run[count($run) - 1];
                $start = $first->getStartLine();
                $end   = $last->getEndLine();
                if ($start <= 0 || $end <= 0) {
                    break; // same line guard the visitor applies
                }
                $wrapper = new Stmt\Block(array_values($run), [
                    'startLine' => $start,
                    'endLine'   => $end,
                ]);
                $size = $count($wrapper);
                if ($size > $maxSize) {
                    array_pop($run);
                    continue; // shared-node inflation: trim, then re-check
                }
                if ($size < $minSize) {
                    break; // sub-floor fragment: dropped by the boilerplate rule
                }
                $window = new Block(
                    file: $file,
                    range: new LineRange($start, $end),
                    kind: BlockExtractor::WINDOW_KIND,
                    namespace: $namespace,
                    class: $class,
                    name: $name,
                    ast: $wrapper,
                );
                $window->size = $size;
                $sink($window);
                $emitted++;
                break;
            }
        }
        return $emitted;
    }
}
