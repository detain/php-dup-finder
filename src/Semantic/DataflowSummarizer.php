<?php
declare(strict_types=1);

namespace Phpdup\Semantic;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Cheap dataflow summary of a block.
 *
 * Walks an AST once and produces a dict of:
 *
 *   - vars       set of distinct variable names defined or used
 *   - returns    list of return-statement shapes
 *   - calls      multiset of called method/function names
 *   - sideEffects   bool: contains echo/print/header/exit/log/...
 *
 * Uses NodeFinder to traverse all descendant nodes including nested
 * closures, so nested functions contribute their variables, calls,
 * returns, and side-effects to the parent block's summary.
 *
 * Used by {@see BehaviouralSimilarity} (and III.A's
 * SemanticHoleClassifier) to compare blocks by I/O shape rather
 * than syntactic structure — the foundation for type-4 clone
 * detection.
 */
final class DataflowSummarizer
{
    /**
     * @return array{
     *   vars: array<string,bool>,
     *   returns: list<string>,
     *   calls: array<string,int>,
     *   sideEffects: bool,
     * }
     */
    public function summarize(Node $block): array
    {
        $vars = [];
        $returns = [];
        $calls = [];
        $sideEffects = false;

        $finder = new NodeFinder();
        $sideEffectFns = ['echo', 'print', 'exit', 'die', 'header'];
        $sideEffectMethods = ['log', 'logger', 'send', 'emit', 'dispatch', 'fire', 'publish', 'notify'];

        foreach ($finder->find([$block], static function (Node $n) {
            return $n instanceof Node\Expr\Variable
                || $n instanceof Node\Expr\MethodCall
                || $n instanceof Node\Expr\StaticCall
                || $n instanceof Node\Expr\FuncCall
                || $n instanceof Node\Stmt\Return_
                || $n instanceof Node\Stmt\Echo_;
        }) as $node) {
            if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
                $vars[$node->name] = true;
            } elseif ($node instanceof Node\Stmt\Return_) {
                $returns[] = $node->expr === null ? '<void>' : $node->expr::class;
            } elseif ($node instanceof Node\Stmt\Echo_) {
                $sideEffects = true;
            } elseif ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
                $name = $node->name->name;
                $calls[$name] = ($calls[$name] ?? 0) + 1;
                $lower = strtolower($name);
                foreach ($sideEffectMethods as $m) {
                    if (str_contains($lower, $m)) { $sideEffects = true; break; }
                }
            } elseif ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
                $name = $node->name->name;
                $calls[$name] = ($calls[$name] ?? 0) + 1;
            } elseif ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $name = $node->name->toString();
                $calls[$name] = ($calls[$name] ?? 0) + 1;
                if (in_array(strtolower($name), $sideEffectFns, true)) $sideEffects = true;
            }
        }

        return [
            'vars'        => $vars,
            'returns'     => $returns,
            'calls'       => $calls,
            'sideEffects' => $sideEffects,
        ];
    }
}
