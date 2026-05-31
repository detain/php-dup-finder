<?php

declare(strict_types=1);

namespace Acme\Common\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * @template T
 */
final class GenericSearchQuery
{
    /**
     * @param array<string, array{column: string, op: string}> $filterMap   filter key -> column/op
     * @param array<int, string> $columns
     * @param callable(array<string, mixed>): T $hydrate
     */
    public function __construct(
        private readonly Connection $db,
        private readonly string $table,
        private readonly string $orderColumn,
        private readonly array $columns,
        private readonly array $filterMap,
        private readonly array $searchableColumns,
        /** @var callable(array<string, mixed>): T */
        private $hydrate,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, T>
     */
    public function search(array $filters): array
    {
        $qb = $this->db->createQueryBuilder();
        $qb->select(...$this->columns)->from($this->table)->orderBy($this->orderColumn, 'DESC');

        if (!empty($filters['q'])) {
            $clauses = array_map(fn (string $c): string => "{$c} LIKE :q", $this->searchableColumns);
            $qb->andWhere('(' . implode(' OR ', $clauses) . ')')
                ->setParameter('q', '%' . $filters['q'] . '%');
        }
        foreach ($this->filterMap as $key => $spec) {
            if (!empty($filters[$key])) {
                $qb->andWhere("{$spec['column']} {$spec['op']} :{$key}")
                    ->setParameter($key, $filters[$key]);
            }
        }

        $qb->setMaxResults($filters['limit'] ?? 50);
        $qb->setFirstResult($filters['offset'] ?? 0);

        return array_map($this->hydrate, $qb->executeQuery()->fetchAllAssociative());
    }
}
