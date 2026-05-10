<?php
declare(strict_types=1);

namespace Phpdup\Ir\Nodes;

use Phpdup\Ir\IrNode;

/**
 * IR leaf representing a scalar literal. Only the *type* survives
 * (`str`, `int`, `float`, `bool`, `null`); the literal value is
 * collapsed so two structurally identical IR trees that differ only
 * by literal payloads cluster.
 */
final class LiteralIr extends IrNode
{
    public function __construct(public readonly string $type) {}

    public function kind(): string
    {
        return 'literal';
    }

    public function scalar(): string
    {
        return $this->type;
    }
}
