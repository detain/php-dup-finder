<?php

declare(strict_types=1);

namespace App\Filter;

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;

final class UserFilter
{
    public function apply(QueryBuilder $qb, array $filters): QueryBuilder
    {
        if (!empty($filters['role'])) {
            $qb->andWhere('u.role = :role')
               ->setParameter('role', $filters['role']);
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('u.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['segment'])) {
            $qb->andWhere('u.segment = :segment')
               ->setParameter('segment', $filters['segment']);
        }

        if (!empty($filters['min_age'])) {
            $qb->andWhere('u.birthDate <= :maxBirthDate')
               ->setParameter('maxBirthDate', (new \DateTime())->modify('-' . $filters['min_age'] . ' years'));
        }

        if (!empty($filters['max_age'])) {
            $qb->andWhere('u.birthDate >= :minBirthDate')
               ->setParameter('minBirthDate', (new \DateTime())->modify('-' . $filters['max_age'] . ' years'));
        }

        if (!empty($filters['verified'])) {
            $verified = filter_var($filters['verified'], FILTER_VALIDATE_BOOLEAN);
            $qb->andWhere('u.emailVerifiedAt ' . ($verified ? 'IS NOT NULL' : 'IS NULL'));
        }

        if (!empty($filters['search'])) {
            $qb->andWhere('u.email LIKE :search OR u.username LIKE :search OR u.fullName LIKE :search')
               ->setParameter('search', '%' . $filters['search'] . '%');
        }

        return $qb;
    }

    public function applySorting(QueryBuilder $qb, ?string $sortBy, ?string $sortOrder): QueryBuilder
    {
        $sortBy = $sortBy ?? 'createdAt';
        $sortOrder = strtoupper($sortOrder ?? 'DESC');

        if (!in_array($sortBy, ['createdAt', 'email', 'username', 'lastLoginAt'])) {
            $sortBy = 'createdAt';
        }

        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'DESC';
        }

        $qb->orderBy('u.' . $sortBy, $sortOrder);

        return $qb;
    }

    public function applyPagination(QueryBuilder $qb, int $page, int $limit): QueryBuilder
    {
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)
           ->setMaxResults($limit);

        return $qb;
    }
}
