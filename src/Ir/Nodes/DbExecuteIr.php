<?php
declare(strict_types=1);

namespace Phpdup\Ir\Nodes;

use Phpdup\Ir\IrNode;

/**
 * IR node representing a `prepare`/`execute`-style operation that
 * isn't enough on its own to classify as a read or write — e.g.
 * the call returns a statement handle or success boolean.
 *
 * Keeping these distinct from {@see DbQueryIr} preserves the
 * shape of two-phase prepared-statement code (prepare + execute +
 * fetch) which clusters by phase-count, not just by verb.
 */
final class DbExecuteIr extends IrNode
{
    public function __construct(
        public readonly string $detail = '?',
    ) {
    }

    public function kind(): string
    {
        return 'db.execute';
    }

    public function scalar(): string
    {
        return $this->detail;
    }
}
