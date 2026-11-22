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
 *   - sideEffects   bool: writes the walk can see — echo via
 *                   Stmt\Echo_, an exact-name `header()` call via
 *                   FuncCall, or a method-call name carrying a
 *                   side-effect verb (log/logger/send/emit/dispatch/
 *                   fire/publish/notify — full camelCase/snake_case
 *                   words, never substrings: engine-028 fold-in).
 *                   print/exit/die are modelled by dedicated
 *                   php-parser expression nodes (Expr\Print_ /
 *                   Expr\Exit_) this summary does NOT yet walk, so
 *                   they never set the flag; adding Exit_/Print_
 *                   arms is a separate behavior leg, out of this
 *                   step's scope (engine-S31c r1).
 *
 * Uses NodeFinder to traverse all descendant nodes including nested
 * closures, so nested functions contribute their variables, calls,
 * returns, and side-effects to the parent block's summary.
 *
 * Consumers (catalog engine-S31c truth pass — the old prose named a
 * "SemanticHoleClassifier" that exists nowhere in src/):
 *   - {@see \Phpdup\Ml\PairFeatures} feeds vars/calls/returns into
 *     the sidecar pair vector; the sideEffects flag itself is not a
 *     wire feature, so this file is inert to ML feature identity.
 *   - {@see \Phpdup\Validation\MutationTester} selects side-effect-
 *     bearing blocks.
 *   - {@see \Phpdup\Similarity\BehaviouralSimilarity} scores by I/O
 *     shape — itself a PLANNED type-4 tier, not wired into the
 *     Clusterer (engine-S31a ledger row 3/16).
 */
final class DataflowSummarizer
{
    /**
     * Exact names the FuncCall arm accepts as a write (exact match — a
     * qualified `Foo\header` name never equals 'header', by design).
     * Of the listed names only `header()` is reachable through the
     * FuncCall arm: echo is caught by its own Stmt\Echo_ arm, and
     * print/exit/die are modelled by dedicated php-parser expression
     * nodes (Expr\Print_ / Expr\Exit_) this walker does not visit —
     * arming them is a separate behavior leg, out of this row's
     * scope (engine-S31c r1).
     */
    private const SIDE_EFFECT_FUNCTIONS = ['echo', 'print', 'exit', 'die', 'header'];

    /**
     * Method-call verbs that mark a side effect. Matched per word of
     * the identifier (camelCase humps, digits, underscores), NOT as
     * substrings: `catalog()`, `dialog()`, `firewall()` or an
     * aggressive-mode stamped `__CALL_catalog` contain 'log'/'fire'
     * as substrings but are not side effects (engine-028 fold-in —
     * the substring test was a false-positive source the moment
     * type-4 scoring is wired). Verb words at any position still
     * match: `log`, `logError`, `sendEmail`, `notify_all`, and a
     * stamped `__CALL_log`.
     */
    private const SIDE_EFFECT_METHOD_WORDS = ['log', 'logger', 'send', 'emit', 'dispatch', 'fire', 'publish', 'notify'];

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
        $sideEffectFns = self::SIDE_EFFECT_FUNCTIONS;

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
                if (!$sideEffects && self::isSideEffectCallName($name)) {
                    $sideEffects = true;
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

    /**
     * True when any word of the method-name identifier is a
     * side-effect verb. The identifier is split on camelCase humps
     * and underscores — the word-boundary rule the engine-028
     * fold-in prescribes for the former `str_contains` substring
     * test. `__CALL_log`-style aggressive-mode sentinels split to
     * `[CALL, log]` and still match; `__CALL_catalog` does not.
     */
    private static function isSideEffectCallName(string $name): bool
    {
        $words = preg_split(
            '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])|_+/',
            $name,
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
        foreach ($words as $word) {
            if (in_array(strtolower($word), self::SIDE_EFFECT_METHOD_WORDS, true)) {
                return true;
            }
        }
        return false;
    }
}
