<?php
declare(strict_types=1);

namespace Acme\Math;

interface MathNode
{
    public function accept(MathVisitor $v): string;
}

final class NumberLiteral implements MathNode
{
    public function __construct(public readonly float $value) {}
    public function accept(MathVisitor $v): string { return $v->visitNumber($this); }
}

final class BinaryOp implements MathNode
{
    public function __construct(
        public readonly string $op,
        public readonly MathNode $left,
        public readonly MathNode $right,
    ) {}
    public function accept(MathVisitor $v): string { return $v->visitBinary($this); }
}

final class UnaryOp implements MathNode
{
    public function __construct(
        public readonly string $op,
        public readonly MathNode $operand,
    ) {}
    public function accept(MathVisitor $v): string { return $v->visitUnary($this); }
}

final class MathPrinter implements MathVisitor
{
    public function visitNumber(NumberLiteral $n): string
    {
        return sprintf('Number(%.2f)', $n->value);
    }

    public function visitBinary(BinaryOp $b): string
    {
        return sprintf('Binary(%s, %s, %s)', $b->op, $b->left->accept($this), $b->right->accept($this));
    }

    public function visitUnary(UnaryOp $u): string
    {
        return sprintf('Unary(%s, %s)', $u->op, $u->operand->accept($this));
    }
}
