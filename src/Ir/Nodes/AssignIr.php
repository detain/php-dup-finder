<?php
declare(strict_types=1);

namespace Phpdup\Ir\Nodes;

use Phpdup\Ir\IrNode;

/**
 * IR node representing a local assignment (`$x = …`, `$x->p = …`,
 * `$x[…] = …`).
 *
 * Carries one child: the right-hand side IR. The left-hand-side
 * shape is collapsed into a coarse `target` tag (`var`, `prop`,
 * `index`, `static-prop`) so semantically identical assigns line
 * up regardless of whether the left-hand side is a local variable
 * or a property.
 */
final class AssignIr extends IrNode
{
    public function __construct(
        public readonly string $target,
        public readonly IrNode $rhs,
    ) {
    }

    public function kind(): string
    {
        return 'assign';
    }

    public function scalar(): string
    {
        return $this->target;
    }

    public function children(): array
    {
        return [$this->rhs];
    }
}
