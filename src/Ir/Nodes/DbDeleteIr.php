<?php
declare(strict_types=1);

namespace Phpdup\Ir\Nodes;

use Phpdup\Ir\IrNode;

/**
 * IR node representing a database delete (`DELETE FROM`,
 * `Model::destroy`, `$em->remove`, `pg_delete`, …).
 */
final class DbDeleteIr extends IrNode
{
    public function __construct(
        public readonly string $table,
        public readonly string $predicate = 'unknown',
    ) {
    }

    #[\Override]
    public function kind(): string
    {
        return 'db.delete';
    }

    #[\Override]
    public function scalar(): string
    {
        return $this->table . ':' . $this->predicate;
    }
}
