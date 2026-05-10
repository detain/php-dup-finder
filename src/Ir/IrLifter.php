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
 * operations (via {@see DbOpRegistry}), assignments, branches,
 * loops, returns, and generic calls — and lifts the rest into a
 * coarse `CallIr` / `LiteralIr` / `VarIr` form.
 *
 * **Failure mode**
 *
 * The lifter is *partial* by design: when an input shape isn't
 * recognised (e.g. `eval`, `goto`, complex variable-variables) the
 * lifter returns `null` and the caller falls back to AST-level
 * scoring. Per the plan's risk-mitigation note: "fall back to AST
 * scoring on lift failure (Lifter returns null)".
 *
 * **Determinism**
 *
 * Two semantically equivalent ASTs lift to byte-identical IR (both
 * via `serialize()` and via {@see IrPrinter}). That is the whole
 * point — IR-to-IR similarity is then a near-trivial computation.
 */
final class IrLifter
{
    public function __construct(
        private readonly DbOpRegistry $registry = new DbOpRegistry(),
    ) {
    }

    /** Top-level entry point: lift an AST node into IR. Returns null on failure. */
    public function lift(Node $node): ?IrNode
    {
        try {
            return $this->liftNode($node);
        } catch (\Throwable) {
            return null;
        }
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
            $else = null;
            if ($node->else !== null) {
                $else = new BlockIr($this->liftStmtList($node->else->stmts));
            } elseif (!empty($node->elseifs)) {
                // Convert elseif chain into nested BranchIr.
                $else = $this->liftElseIfChain($node->elseifs);
            }
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

    /** @param list<Node\Stmt\ElseIf_> $elseifs */
    private function liftElseIfChain(array $elseifs): BranchIr
    {
        $head = array_shift($elseifs);
        $cond = $this->liftExpr($head->cond);
        $then = new BlockIr($this->liftStmtList($head->stmts));
        $else = $elseifs === [] ? null : $this->liftElseIfChain($elseifs);
        return new BranchIr($cond, $then, $else);
    }

    private function liftSwitch(Node\Stmt\Switch_ $node): IrNode
    {
        // Unfold each case into a chain of BranchIr — each case becomes
        // an `if (subject == case_value) <body> else <next>`.
        $subject = $this->liftExpr($node->cond);
        $cases = $node->cases;
        return $this->buildSwitchChain($subject, $cases, 0);
    }

    /** @param list<Node\Stmt\Case_> $cases */
    private function buildSwitchChain(IrNode $subject, array $cases, int $i): IrNode
    {
        if (!isset($cases[$i])) {
            return new BlockIr([]);
        }
        $case = $cases[$i];
        $body = new BlockIr($this->liftStmtList($case->stmts));
        if ($case->cond === null) {
            // default
            return $body;
        }
        return new BranchIr(
            new CallIr('==', [$subject, $this->liftExpr($case->cond)]),
            $body,
            $this->buildSwitchChain($subject, $cases, $i + 1),
        );
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

    /** @param array<int,Node\Arg|Node\VariadicPlaceholder> $args
     *  @return list<IrNode> */
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

    /** @param array<int,Node\Arg|Node\VariadicPlaceholder> $args */
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
