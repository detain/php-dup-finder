<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use PhpParser\Node;
use Phpdup\Util\AstSerializer;

/**
 * Item 4.1: the compact, wire-safe observation record co-written beside
 * every hole row by {@see AntiUnifier}.
 *
 * The doc's original prescription for the drift analyzer was to keep a
 * raw node reference per observation. Refuted by the research pass
 * (improve_logic_info.md §4.1, f11): holes cross the WorkerPool
 * socketpair as serialized payloads during the parallel refactor —
 * raw nodes would pickle member subtrees per divergence per member,
 * and under lazyAst the parent-side member ASTs are null anyway, so
 * the literal prescription would silently produce zero findings in
 * parallel mode. The replacement is this class: a plain-scalar array
 * `array{type: string, callNames: list<string>, containsExit: bool,
 * scalar: ?string}` computed where the node is already in hand
 * (`observe()`/hole birth), wire-safe (serialize() of scalars only),
 * and cache-neutral (derived from the already-cached structure at
 * anti-unify time; no cache-key bump).
 *
 * The {@see self::ABSENT_TYPE} marker is a separate honesty channel:
 * an optional-segment row recorded as a VERIFIED ABSENCE (the walk saw
 * the slot and it held no template statement) carries this descriptor
 * instead of null, while never-gathered gap rows carry no descriptor
 * at all. That distinction — which the hole-wide `degraded` flag
 * cannot draw per row (output-019 grammar) — is what lets the drift
 * analyzer name a deficient member on a degraded alignment without
 * ever claiming against an unobserved row.
 *
 * Traversal is iterative and bounded by the block size ceiling (800
 * nodes in production extraction), matching AstSerializer's own
 * posture; call names cap at {@see self::MAX_CALL_NAMES} unique
 * entries in traversal order, so equal content always yields an equal
 * descriptor (deterministic by construction).
 */
final class ObservationDescriptor
{
    /** Descriptor type of a verified-absence row (see class docblock). */
    public const ABSENT_TYPE = 'absent';

    /** Upper bound on distinct callee names recorded per observation. */
    public const MAX_CALL_NAMES = 8;

    /**
     * Build the descriptor of one observed node.
     *
     * @return array{type: string, callNames: list<string>, containsExit: bool, scalar: ?string}
     */
    public static function build(Node $node): array
    {
        $callNames    = [];
        $seen         = [];
        $containsExit = false;

        $stack = [$node];
        while ($stack !== []) {
            $current = array_pop($stack);

            if (!$containsExit && self::isExitNode($current)) {
                $containsExit = true;
            }
            if (count($callNames) < self::MAX_CALL_NAMES) {
                $name = self::callName($current);
                if ($name !== null && !isset($seen[$name])) {
                    $seen[$name] = true;
                    $callNames[] = $name;
                }
            }

            $children = [];
            foreach ($current->getSubNodeNames() as $sub) {
                $value = $current->$sub;
                if ($value instanceof Node) {
                    $children[] = $value;
                    continue;
                }
                if (is_array($value)) {
                    foreach ($value as $child) {
                        if ($child instanceof Node) {
                            $children[] = $child;
                        }
                    }
                }
            }
            // Push reversed: the stack pops the LAST entry, so this is
            // what keeps callNames in true first-seen (source) order.
            for ($k = count($children) - 1; $k >= 0; $k--) {
                $stack[] = $children[$k];
            }
        }

        return [
            'type'         => AstSerializer::shortType($node),
            'callNames'    => $callNames,
            'containsExit' => $containsExit,
            'scalar'       => AstSerializer::scalarPart($node),
        ];
    }

    /**
     * The descriptor of a VERIFIED ABSENCE row: the walk saw this
     * member's slot at an optional segment and it held no template
     * statement. Not a node observation — a witnessed negative — hence
     * the distinct type and the empty call/exit/scalar payload.
     *
     * @return array{type: string, callNames: list<string>, containsExit: bool, scalar: ?string}
     */
    public static function absent(): array
    {
        return [
            'type'         => self::ABSENT_TYPE,
            'callNames'    => [],
            'containsExit' => false,
            'scalar'       => null,
        ];
    }

    /**
     * Does this node constitute a control-flow exit? Return statements,
     * throw expressions, and exit/die (both the Expr\Exit_ node and the
     * legacy `exit()`/`die()` function-call forms).
     */
    private static function isExitNode(Node $node): bool
    {
        if ($node instanceof Node\Stmt\Return_) {
            return true;
        }
        if ($node instanceof Node\Expr\Throw_ || $node instanceof Node\Expr\Exit_) {
            return true;
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $lower = strtolower($node->name->toString());
            return $lower === 'exit' || $lower === 'die';
        }
        return false;
    }

    /**
     * The callee name a call node dispatches to, when it is a plain
     * static name (function, `Class::method`, or `->method` with a
     * literal identifier). Dynamic dispatches (`$f()`, `$o->$m()`) are
     * honest nulls — a name we cannot state is not a name we guess.
     */
    private static function callName(Node $node): ?string
    {
        if ($node instanceof Node\Expr\FuncCall) {
            return $node->name instanceof Node\Name ? $node->name->toString() : null;
        }
        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            return $node->name instanceof Node\Identifier ? $node->name->toString() : null;
        }
        if ($node instanceof Node\Expr\StaticCall) {
            return $node->name instanceof Node\Identifier ? $node->name->toString() : null;
        }
        return null;
    }
}
