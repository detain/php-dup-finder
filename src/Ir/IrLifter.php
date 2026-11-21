<?php
declare(strict_types=1);

namespace Phpdup\Ir;

use PhpParser\Node;
use Phpdup\Ir\Nodes\AssignIr;
use Phpdup\Ir\Nodes\BlockIr;
use Phpdup\Ir\Nodes\BranchIr;
use Phpdup\Ir\Nodes\CallIr;
use Phpdup\Ir\Nodes\DbDeleteIr;
use Phpdup\Ir\Nodes\DbExecuteIr;
use Phpdup\Ir\Nodes\DbQueryIr;
use Phpdup\Ir\Nodes\DbReadIr;
use Phpdup\Ir\Nodes\DbWriteIr;
use Phpdup\Ir\Nodes\LiteralIr;
use Phpdup\Ir\Nodes\LoopIr;
use Phpdup\Ir\Nodes\ReturnIr;
use Phpdup\Ir\Nodes\VarIr;
use Phpdup\Normalization\DbOpRegistry;
use Phpdup\Normalization\SqlTableExtractor;

/**
 * Lifts a PhpParser AST node into the phpdup IR.
 *
 * Recognises a small but useful subset of PHP semantics — DB
 * operations (via {@see DbOpRegistry}), simple assignments,
 * compound assignments (+=, -=, .=, ??=, etc.), pre/post inc/dec,
 * branches, loops, returns, and generic calls — and lifts the rest
 * into a coarse `CallIr` / `LiteralIr` / `VarIr` form.
 *
 * **Failure mode**
 *
 * The lifter is *partial* by design: when an input shape isn't
 * recognised (e.g. `eval`, `goto`, complex variable-variables) the
 * lifter returns `null` and the caller falls back to AST-level
 * scoring. Per the plan's risk-mitigation note: "fall back to AST
 * scoring on lift failure (Lifter returns null)".
 *
 * Only `\Error`-family failures are laundered into that `null`;
 * programmer bugs in any `\Exception` branch propagate loudly
 * instead of hiding as a silent fallback. Refused lifts are
 * observable via {@see liftRefusals()} / {@see lastLiftError()}.
 *
 * **Determinism**
 *
 * Two semantically equivalent ASTs lift to byte-identical IR (both
 * via `serialize()` and via {@see IrPrinter}). That is the whole
 * point — IR-to-IR similarity is then a near-trivial computation.
 * `switch` is desugared into the same nested-`BranchIr` if/elseif
 * chain a hand-written counterpart produces — including the
 * `equal` comparison symbol a genuine `$x == $v` lifts to — so a
 * switch and its if-chain form emit identical token streams.
 */
final class IrLifter
{
    /** @var int<0, max> Count of \Error-family lifts refused since construction. */
    private int $refusalCount = 0;

    /** Message of the most recent refused lift, for observability. */
    private ?string $lastLiftError = null;

    public function __construct(
        private readonly DbOpRegistry $registry = new DbOpRegistry(),
    ) {
    }

    /** Top-level entry point: lift an AST node into IR. Returns null on failure. */
    public function lift(Node $node): ?IrNode
    {
        try {
            return $this->liftNode($node);
        } catch (\Error $error) {
            // An unsupported input shape surfacing as an engine-level
            // error (\Error, \TypeError) is the documented graceful
            // fallback. Programmer bugs in any \Exception branch are
            // NOT swallowed here — laundering them hid lifter defects.
            $this->refusalCount++;
            $this->lastLiftError = $error->getMessage();
            return null;
        }
    }

    /** Number of \Error-family lifts this instance has refused (observability hook). */
    public function liftRefusals(): int
    {
        return $this->refusalCount;
    }

    /** Message of the most recent refused lift, or null when none was refused. */
    public function lastLiftError(): ?string
    {
        return $this->lastLiftError;
    }

    private function liftNode(Node $node): IrNode
    {
        // Container statements with their own bodies.
        if ($node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
        ) {
            $stmts = $node instanceof Node\Expr\ArrowFunction
                ? [new Node\Stmt\Return_($node->expr)]
                : ($node->stmts ?? []);
            return new BlockIr($this->liftStmtList($stmts));
        }

        if ($node instanceof Node\Stmt\Expression) {
            return $this->liftExpr($node->expr);
        }

        if ($node instanceof Node\Stmt\Return_) {
            return new ReturnIr($node->expr !== null ? $this->liftExpr($node->expr) : null);
        }

        if ($node instanceof Node\Stmt\If_) {
            $cond = $this->liftExpr($node->cond);
            $then = new BlockIr($this->liftStmtList($node->stmts));
            // An if may carry elseifs AND a final else; both must
            // survive as one nested chain (the else is the chain's
            // terminal), otherwise a three-arm if silently loses its
            // elseif bodies.
            $else = $this->liftElseIfChain($node->elseifs, $node->else);
            return new BranchIr($cond, $then, $else);
        }

        if ($node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\Do_
            || $node instanceof Node\Stmt\Foreach_
        ) {
            return new LoopIr(new BlockIr($this->liftStmtList($node->stmts)));
        }

        if ($node instanceof Node\Stmt\Switch_) {
            return $this->liftSwitch($node);
        }

        if ($node instanceof Node\Expr) {
            return $this->liftExpr($node);
        }

        // Fallback: serialise as a generic call against the node class
        // name so distinct unrecognised constructs are at least
        // distinguishable.
        return new CallIr(strtolower(self::shortClass($node)));
    }

    /**
     * Fold an elseif chain into nested BranchIr nodes whose terminal
     * `else` is the trailing else block (null when the chain simply
     * ends). Empty chain with no trailing else lifts to null.
     *
     * @param list<Node\Stmt\ElseIf_> $elseifs
     */
    private function liftElseIfChain(array $elseifs, ?Node\Stmt\Else_ $trailingElse): ?IrNode
    {
        $head = array_shift($elseifs);
        if ($head === null) {
            return $trailingElse === null
                ? null
                : new BlockIr($this->liftStmtList($trailingElse->stmts));
        }
        $cond = $this->liftExpr($head->cond);
        $then = new BlockIr($this->liftStmtList($head->stmts));
        return new BranchIr($cond, $then, $this->liftElseIfChain($elseifs, $trailingElse));
    }

    private function liftSwitch(Node\Stmt\Switch_ $node): IrNode
    {
        // Unfold each case into a chain of BranchIr — each case becomes
        // an `if (subject == case_value) <body> else <next>`. The
        // comparison uses the exact symbol shortBinaryOp() gives a
        // genuine `$x == $v`, so a switch and its hand-written if-chain
        // counterpart lift to identical IR.
        $subject = $this->liftExpr($node->cond);
        $cases = $node->cases;

        $defaultIndex = null;
        foreach ($cases as $i => $case) {
            if ($case->cond === null) {
                $defaultIndex = $i;
                break; // first default is the else terminal; later ones fold into it
            }
        }
        $else = $defaultIndex === null
            ? null
            : new BlockIr($this->liftStmtList($this->foldCaseStmts($cases, $defaultIndex)));

        return $this->buildSwitchChain($subject, $cases, $defaultIndex, $else);
    }

    /**
     * Emit the chain arms: every non-default case keeps its test in
     * physical position order, each body is the folded fallthrough
     * body, and the deepest `else` is the default's folded body — or
     * null, exactly like an if/elseif chain that has no else (no
     * empty trailing BlockIr residue).
     *
     * @param list<Node\Stmt\Case_> $cases
     */
    private function buildSwitchChain(
        IrNode $subject,
        array $cases,
        ?int $defaultIndex,
        ?IrNode $else
    ): IrNode {
        $arms = [];
        foreach ($cases as $i => $case) {
            if ($case->cond === null) {
                continue; // the (first) default is the else terminal; duplicates fold into it
            }
            $arms[] = [$case->cond, $this->foldCaseStmts($cases, $i)];
        }
        $chain = $else;
        for ($k = count($arms) - 1; $k >= 0; $k--) {
            $chain = new BranchIr(
                new CallIr(self::compareSymbol(), [$subject, $this->liftExpr($arms[$k][0])]),
                new BlockIr($this->liftStmtList($arms[$k][1])),
                $chain,
            );
        }
        return $chain ?? new BlockIr([]);
    }

    /**
     * Fold PHP's physical fallthrough into each case's effective
     * statement list: a case whose last statement is a bare `break;`
     * ends there; otherwise the next physical case's folded body is
     * appended (so cases after `default:` are kept, reached whenever
     * execution falls through into them).
     *
     * Elision policy: only a trailing bare break is recognised as a
     * terminator, and only that one is stripped — `break 2;` and
     * breaks that are not the final statement keep their
     * `call:break_` token and leave the case folding onward. Pinning
     * this keeps the desugaring stable rather than clever.
     *
     * @param list<Node\Stmt\Case_> $cases
     * @return list<Node\Stmt>
     */
    private function foldCaseStmts(array $cases, int $i): array
    {
        $stmts = $cases[$i]->stmts;
        $last = $stmts === [] ? null : $stmts[count($stmts) - 1];
        if ($last instanceof Node\Stmt\Break_ && $last->num === null) {
            return array_slice($stmts, 0, count($stmts) - 1);
        }
        if (!isset($cases[$i + 1])) {
            return $stmts; // falls off the end of the switch
        }
        return array_merge($stmts, $this->foldCaseStmts($cases, $i + 1));
    }

    /**
     * The IR symbol for a switch arm's comparison, derived (not
     * re-spelled) so it can never drift from what `==` lifts to.
     */
    private static function compareSymbol(): string
    {
        return self::shortBinaryOp(new Node\Expr\BinaryOp\Equal(
            new Node\Expr\ConstFetch(new Node\Name('null')),
            new Node\Expr\ConstFetch(new Node\Name('null')),
        ));
    }

    /**
     * @param list<Node\Stmt> $stmts
     * @return list<IrNode>
     */
    private function liftStmtList(array $stmts): array
    {
        $out = [];
        foreach ($stmts as $stmt) {
            $out[] = $this->liftNode($stmt);
        }
        return $out;
    }

    private function liftExpr(Node\Expr $expr): IrNode
    {
        // Assignments — collapse the LHS shape.
        if ($expr instanceof Node\Expr\Assign) {
            $target = match (true) {
                $expr->var instanceof Node\Expr\Variable          => 'var',
                $expr->var instanceof Node\Expr\PropertyFetch     => 'prop',
                $expr->var instanceof Node\Expr\StaticPropertyFetch => 'static-prop',
                $expr->var instanceof Node\Expr\ArrayDimFetch     => 'index',
                default                                            => 'other',
            };
            return new AssignIr($target, $this->liftExpr($expr->expr));
        }

        // AssignOp: +=, -=, .=, ??=, etc.
        if ($expr instanceof Node\Expr\AssignOp) {
            $target = match (true) {
                $expr->var instanceof Node\Expr\Variable          => 'var',
                $expr->var instanceof Node\Expr\PropertyFetch     => 'prop',
                $expr->var instanceof Node\Expr\StaticPropertyFetch => 'static-prop',
                $expr->var instanceof Node\Expr\ArrayDimFetch     => 'index',
                default                                            => 'other',
            };
            // Concat needs to reconstruct the binary op so that `$x .= $y`
            // lifts to the same IR as `$x = $x . $y`.
            if ($expr instanceof Node\Expr\AssignOp\Concat) {
                $reconstructed = new Node\Expr\BinaryOp\Concat($expr->var, $expr->expr);
                return new AssignIr($target, $this->liftExpr($reconstructed));
            }
            // Other AssignOp (+=, -=, ??=, etc.) are treated as mutations.
            return new AssignIr($target, new LiteralIr('int'));
        }

        // Pre/Post Inc/Dec — treated as var assignments (+= 1 or -= 1).
        if ($expr instanceof Node\Expr\PreInc || $expr instanceof Node\Expr\PostInc
            || $expr instanceof Node\Expr\PreDec || $expr instanceof Node\Expr\PostDec) {
            return new AssignIr('var', new LiteralIr('int'));
        }

        // DB-recognised calls.
        $dbIr = $this->tryLiftDbCall($expr);
        if ($dbIr !== null) {
            return $dbIr;
        }

        // Generic calls.
        if ($expr instanceof Node\Expr\StaticCall) {
            $sym = ($expr->name instanceof Node\Identifier ? strtolower($expr->name->name) : 'unknown');
            return new CallIr($sym, $this->liftArgs($expr->args));
        }
        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall) {
            $sym = ($expr->name instanceof Node\Identifier ? strtolower($expr->name->name) : 'unknown');
            return new CallIr($sym, $this->liftArgs($expr->args));
        }
        if ($expr instanceof Node\Expr\FuncCall) {
            $sym = ($expr->name instanceof Node\Name ? strtolower($expr->name->toString()) : 'unknown');
            return new CallIr($sym, $this->liftArgs($expr->args));
        }

        // Variables and literals.
        if ($expr instanceof Node\Expr\Variable) {
            return new VarIr();
        }
        if ($expr instanceof Node\Scalar\String_) {
            return new LiteralIr('str');
        }
        if ($expr instanceof Node\Scalar\Int_) {
            return new LiteralIr('int');
        }
        if ($expr instanceof Node\Scalar\Float_) {
            return new LiteralIr('float');
        }
        if ($expr instanceof Node\Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());
            if (in_array($name, ['true', 'false'], true)) {
                return new LiteralIr('bool');
            }
            if ($name === 'null') {
                return new LiteralIr('null');
            }
            return new CallIr('const:' . $name);
        }

        // Binary ops and ternary fall through to a generic CallIr.
        if ($expr instanceof Node\Expr\BinaryOp) {
            return new CallIr(
                self::shortBinaryOp($expr),
                [$this->liftExpr($expr->left), $this->liftExpr($expr->right)],
            );
        }
        if ($expr instanceof Node\Expr\Ternary) {
            return new BranchIr(
                $this->liftExpr($expr->cond),
                $expr->if !== null ? $this->liftExpr($expr->if) : new LiteralIr('null'),
                $this->liftExpr($expr->else),
            );
        }

        // Last resort: a CallIr labelled by node class so distinct
        // unrecognised constructs don't all collapse to the same shape.
        return new CallIr(strtolower(self::shortClass($expr)));
    }

    /**
     * php-parser 5.9 types CallLike `$args` as a key-unspecified array of
     * Arg|VariadicPlaceholder|ArgPlaceholder. Both placeholder kinds are
     * syntax holes with no value to lift (first-class callables and partial
     * application), so the Arg guard below skips them — as it already did.
     *
     * @param array<array-key,Node\Arg|Node\VariadicPlaceholder|Node\ArgPlaceholder> $args
     * @return list<IrNode>
     */
    private function liftArgs(array $args): array
    {
        $out = [];
        foreach ($args as $a) {
            if ($a instanceof Node\Arg) {
                $out[] = $this->liftExpr($a->value);
            }
        }
        return $out;
    }

    private function tryLiftDbCall(Node\Expr $expr): ?IrNode
    {
        // Static call.
        if ($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier) {
            $op = $this->registry->lookupMethod($expr->name->name);
            if ($op === null) {
                return null;
            }
            $entity = $expr->class instanceof Node\Name ? self::lastSegment($expr->class->toString()) : '?';
            return self::buildDbIr($op, $entity, $expr);
        }
        // Method call.
        if (($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall)
            && $expr->name instanceof Node\Identifier
        ) {
            $op = $this->registry->lookupMethod($expr->name->name);
            if ($op === null) {
                return null;
            }
            // Doctrine-style: first arg may be a class const; surface as entity.
            $entity = self::extractEntityFromFirstArg($expr->args) ?? '?';
            return self::buildDbIr($op, $entity, $expr);
        }
        // Function call.
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
            $name = $expr->name->toString();
            $op = $this->registry->lookupFunction($name);
            if ($op === null) {
                return null;
            }
            return self::buildDbIr($op, '?', $expr);
        }
        return null;
    }

    private static function buildDbIr(string $op, string $entity, Node\Expr $expr): IrNode
    {
        // For Q/X ops, try to lift a SQL string from the first arg
        // and surface its verb + table as a richer IR node.
        $args = $expr instanceof Node\Expr\StaticCall ? $expr->args
              : ($expr instanceof Node\Expr\MethodCall ? $expr->args
              : ($expr instanceof Node\Expr\NullsafeMethodCall ? $expr->args
              : ($expr instanceof Node\Expr\FuncCall ? $expr->args
              : [])));
        if (in_array($op, [DbOpRegistry::OP_QUERY, DbOpRegistry::OP_EXECUTE], true)) {
            foreach ($args as $a) {
                if ($a instanceof Node\Arg && $a->value instanceof Node\Scalar\String_) {
                    $parsed = SqlTableExtractor::extract($a->value->value);
                    if ($parsed !== null) {
                        [$verb, $table] = $parsed;
                        return match ($verb) {
                            'SELECT'             => new DbReadIr($table ?? '?', 'sql'),
                            'INSERT', 'UPDATE',
                            'REPLACE'            => new DbWriteIr($table ?? '?', 'sql'),
                            'DELETE'             => new DbDeleteIr($table ?? '?', 'sql'),
                            default              => new DbQueryIr($verb, $table ?? '?'),
                        };
                    }
                }
            }
        }
        return match ($op) {
            DbOpRegistry::OP_READ    => new DbReadIr(strtolower($entity)),
            DbOpRegistry::OP_WRITE   => new DbWriteIr(strtolower($entity)),
            DbOpRegistry::OP_DELETE  => new DbDeleteIr(strtolower($entity)),
            DbOpRegistry::OP_EXECUTE => new DbExecuteIr(strtolower($entity)),
            default                  => new DbQueryIr('?', strtolower($entity)),
        };
    }

    /**
     * The entity is present only when the first arg is a real Arg; any
     * placeholder (VariadicPlaceholder / php-parser 5.9 ArgPlaceholder)
     * or a key-unspecified list with no 0 entry yields null — as before.
     *
     * @param array<array-key,Node\Arg|Node\VariadicPlaceholder|Node\ArgPlaceholder> $args
     */
    private static function extractEntityFromFirstArg(array $args): ?string
    {
        $first = $args[0] ?? null;
        if (!$first instanceof Node\Arg) {
            return null;
        }
        if ($first->value instanceof Node\Expr\ClassConstFetch
            && $first->value->class instanceof Node\Name
            && $first->value->name instanceof Node\Identifier
            && strtolower($first->value->name->name) === 'class'
        ) {
            return self::lastSegment($first->value->class->toString());
        }
        if ($first->value instanceof Node\Scalar\String_) {
            return $first->value->value;
        }
        return null;
    }

    private static function lastSegment(string $name): string
    {
        $parts = explode('\\', $name);
        return (string)end($parts);
    }

    private static function shortClass(Node $n): string
    {
        $cls = $n::class;
        $idx = strrpos($cls, '\\');
        return $idx === false ? $cls : substr($cls, $idx + 1);
    }

    private static function shortBinaryOp(Node\Expr\BinaryOp $b): string
    {
        return strtolower(str_replace('PhpParser\\Node\\Expr\\BinaryOp\\', '', $b::class));
    }
}
