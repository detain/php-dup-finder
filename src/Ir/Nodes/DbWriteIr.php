<?php
declare(strict_types=1);

namespace Phpdup\Ir\Nodes;

use Phpdup\Ir\IrNode;

/**
 * IR node representing a database write — INSERT / UPDATE / save /
 * persist / flush / etc.
 *
 * Captures `(table, predicate)` analogously to {@see DbReadIr};
 * the predicate distinguishes a primary-key UPDATE from a where-
 * clause UPDATE from a free-form INSERT.
 */
final class DbWriteIr extends IrNode
{
    public function __construct(
        public readonly string $table,
        public readonly string $predicate = 'unknown',
    ) {
    }

    public function kind(): string
    {
        return 'db.write';
    }

    public function scalar(): string
    {
        return $this->table . ':' . $this->predicate;
    }
}
