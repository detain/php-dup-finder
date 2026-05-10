<?php
declare(strict_types=1);

namespace Phpdup\Ir\Nodes;

use Phpdup\Ir\IrNode;

/**
 * IR composite representing a sequence of statements (a function/
 * method body, an if-arm body, a loop body).
 *
 * Statement order is preserved: ordering matters for clustering
 * read/write/save sequences distinctly from the same operations
 * in different orders.
 */
final class BlockIr extends IrNode
{
    /** @param list<IrNode> $stmts */
    public function __construct(public readonly array $stmts = []) {}

    public function kind(): string
    {
        return 'block';
    }

    public function children(): array
    {
        return $this->stmts;
    }
}
