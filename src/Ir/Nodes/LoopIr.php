<?php
declare(strict_types=1);

namespace Phpdup\Ir\Nodes;

use Phpdup\Ir\IrNode;

/**
 * IR node representing a single iteration construct (for / foreach /
 * while / do-while).
 *
 * The PHP-level distinction between for / foreach / while / do is
 * intentionally erased — they all compute "a body executed once
 * per element of some sequence". Two loops over the same sequence
 * shape cluster regardless of which keyword the developer chose.
 */
final class LoopIr extends IrNode
{
    public function __construct(
        public readonly IrNode $body,
    ) {
    }

    public function kind(): string
    {
        return 'loop';
    }

    public function children(): array
    {
        return [$this->body];
    }
}
