<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

class UserRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function searchUsers(string $query, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->select('u.id', 'u.email', 'u.name', 'u.status', 'u.created_at')
           ->from('users', 'u')
           ->where('u.deleted_at IS NULL')
           ->andWhere($qb->expr()->or(
               $qb->expr()->like('u.name', ':query'),
               $qb->expr()->like('u.email', ':query'),
               $qb->expr()->like('u.id', ':query')
           ))
           ->setParameter('query', '%' . $query . '%')
           ->orderBy('u.name', 'ASC')
           ->setMaxResults($limit)
           ->setFirstResult($offset);

        if (isset($filters['status'])) {
            $qb->andWhere('u.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (isset($filters['role'])) {
            $qb->join('u', 'user_roles', 'ur', 'u.id = ur.user_id')
               ->join('ur', 'roles', 'r', 'ur.role_id = r.id')
               ->andWhere('r.slug = :role')
               ->setParameter('role', $filters['role']);
        }

        if (isset($filters['organization_id'])) {
            $qb->andWhere('u.organization_id = :org_id')
               ->setParameter('org_id', $filters['organization_id']);
        }

        if (isset($filters['created_after'])) {
            $qb->andWhere('u.created_at >= :created_after')
               ->setParameter('created_after', $filters['created_after']);
        }

        if (isset($filters['created_before'])) {
            $qb->andWhere('u.created_at <= :created_before')
               ->setParameter('created_before', $filters['created_before']);
        }

        return $qb->execute()->fetchAll();
    }

    public function countSearchResults(string $query, array $filters = []): int
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->select('COUNT(DISTINCT u.id)')
           ->from('users', 'u')
           ->where('u.deleted_at IS NULL')
           ->andWhere($qb->expr()->or(
               $qb->expr()->like('u.name', ':query'),
               $qb->expr()->like('u.email', ':query'),
               $qb->expr()->like('u.id', ':query')
           ))
           ->setParameter('query', '%' . $query . '%');

        if (isset($filters['status'])) {
            $qb->andWhere('u.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (isset($filters['role'])) {
            $qb->join('u', 'user_roles', 'ur', 'u.id = ur.user_id')
               ->join('ur', 'roles', 'r', 'ur.role_id = r.id')
               ->andWhere('r.slug = :role')
               ->setParameter('role', $filters['role']);
        }

        if (isset($filters['organization_id'])) {
            $qb->andWhere('u.organization_id = :org_id')
               ->setParameter('org_id', $filters['organization_id']);
        }

        return (int) $qb->execute()->fetchColumn();
    }
}
