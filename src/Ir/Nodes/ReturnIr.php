<?php
declare(strict_types=1);

namespace Phpdup\Ir\Nodes;

use Phpdup\Ir\IrNode;

/**
 * IR node representing a return statement. The returned expression
 * is the sole child; bare `return;` carries no child.
 */
final class ReturnIr extends IrNode
{
    public function __construct(public readonly ?IrNode $expr = null) {}

    public function kind(): string
    {
        return 'return';
    }

    public function children(): array
    {
        return $this->expr !== null ? [$this->expr] : [];
    }
}
