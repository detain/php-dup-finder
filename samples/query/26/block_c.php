<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

trait SearchableRepositoryTrait
{
    abstract protected function getSearchableColumns(): array;

    abstract protected function getSearchableJoins(): array;

    protected function buildSearchQuery(Connection $connection, string $query, array $filters = []): QueryBuilder
    {
        $qb = $connection->createQueryBuilder();

        $columns = $this->getSearchableColumns();
        $qb->select('DISTINCT ' . implode(', ', $columns))
           ->from($this->getTableName(), $this->getTableAlias());

        $searchConditions = array_map(
            fn($col) => $qb->expr()->like($col, ':query'),
            $columns
        );

        $qb->where('deleted_at IS NULL')
           ->andWhere($qb->expr()->or(...$searchConditions))
           ->setParameter('query', '%' . $query . '%')
           ->orderBy($columns[0], 'ASC');

        return $qb;
    }

    protected function applyFilters(QueryBuilder $qb, array $filters): void
    {
        foreach ($filters as $key => $value) {
            if ($key === 'status') {
                $qb->andWhere($this->getTableAlias() . '.status = :status')
                   ->setParameter('status', $value);
            }
        }
    }
}

class UserRepository
{
    use SearchableRepositoryTrait;

    protected function getSearchableColumns(): array
    {
        return ['u.id', 'u.name', 'u.email'];
    }

    protected function getSearchableJoins(): array
    {
        return [];
    }

    protected function getTableName(): string
    {
        return 'users';
    }

    protected function getTableAlias(): string
    {
        return 'u';
    }

    public function searchUsers(string $query, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $qb = $this->buildSearchQuery($this->connection, $query, $filters);
        $this->applyFilters($qb, $filters);
        $qb->setMaxResults($limit)->setFirstResult($offset);

        return $qb->execute()->fetchAll();
    }
}
