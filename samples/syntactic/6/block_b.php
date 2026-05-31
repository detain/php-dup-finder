<?php
declare(strict_types=1);

namespace Acme\Persistence;

final class QuerySpec
{
    private function __construct(
        private string $table,
        private array $columns,
        private array $where,
        private string $orderBy,
        private int $limit,
    ) {
    }

    public static function from(string $table): self
    {
        return new self(
            table:   $table,
            columns: ['*'],
            where:   [],
            orderBy: 'id ASC',
            limit:   100,
        );
    }

    public function withColumns(array $columns): self
    {
        $copy = clone $this;
        $copy->columns = $columns;
        return $copy;
    }

    public function withWhere(array $where): self
    {
        $copy = clone $this;
        $copy->where = $where;
        return $copy;
    }

    public function withOrderBy(string $orderBy): self
    {
        $copy = clone $this;
        $copy->orderBy = $orderBy;
        return $copy;
    }

    public function withLimit(int $limit): self
    {
        $copy = clone $this;
        $copy->limit = $limit;
        return $copy;
    }

    public function toSql(): string
    {
        $cols = implode(', ', $this->columns);
        $wh = $this->where === [] ? '' : ' WHERE ' . implode(' AND ', $this->where);
        return sprintf('SELECT %s FROM %s%s ORDER BY %s LIMIT %d', $cols, $this->table, $wh, $this->orderBy, $this->limit);
    }
}
