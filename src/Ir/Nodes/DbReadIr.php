<?php
declare(strict_types=1);

namespace Phpdup\Ir\Nodes;

use Phpdup\Ir\IrNode;

/**
 * IR node representing a single database read.
 *
 * Lifted from any `DbOpRegistry::OP_READ` call (Eloquent
 * `Model::find`, Doctrine `$em->find`, PDO `fetch*`, etc.) and from
 * raw `SELECT` SQL statements.
 *
 * The two scalars — `$table` and `$predicate` — capture the
 * minimal shape of a read in a library-/dialect-agnostic way:
 *
 *   - `$table` is the lower-cased entity/table name when known
 *     ("user", "users", …) or `?` when not extractable.
 *   - `$predicate` is a coarse marker for the lookup style
 *     ("id", "where", "all", "first") so a "find by primary key"
 *     read is distinguishable from a "where clause" read in
 *     similarity scoring.
 */
final class DbReadIr extends IrNode
{
    public function __construct(
        public readonly string $table,
        public readonly string $predicate = 'unknown',
    ) {
    }

    public function kind(): string
    {
        return 'db.read';
    }

    public function scalar(): string
    {
        return $this->table . ':' . $this->predicate;
    }
}
