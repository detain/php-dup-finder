<?php
declare(strict_types=1);

namespace Phpdup\Ir\Nodes;

use Phpdup\Ir\IrNode;

/**
 * IR node representing a generic database query (`->query(...)`,
 * `mysqli_query`, raw `SELECT` strings without enough structure to
 * fold into a {@see DbReadIr}).
 *
 * Carries the SQL verb (`SELECT` / `INSERT` / `UPDATE` / `DELETE` /
 * `REPLACE` / `?`) so a SELECT-shaped raw query clusters with a
 * SELECT-shaped Eloquent read at IR-similarity time.
 */
final class DbQueryIr extends IrNode
{
    public function __construct(
        public readonly string $verb,
        public readonly string $table = '?',
    ) {
    }

    public function kind(): string
    {
        return 'db.query';
    }

    public function scalar(): string
    {
        return $this->verb . ':' . $this->table;
    }
}
