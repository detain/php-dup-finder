<?php

declare(strict_types=1);

namespace App\Filter;

use App\Entity\Order;
use Doctrine\ORM\QueryBuilder;

final class OrderFilter
{
    public function apply(QueryBuilder $qb, array $filters): QueryBuilder
    {
        if (!empty($filters['status'])) {
            $qb->andWhere('o.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['customer_id'])) {
            $qb->andWhere('o.customerId = :customerId')
               ->setParameter('customerId', $filters['customer_id']);
        }

        if (!empty($filters['min_total'])) {
            $qb->andWhere('o.total >= :minTotal')
               ->setParameter('minTotal', (float) $filters['min_total']);
        }

        if (!empty($filters['max_total'])) {
            $qb->andWhere('o.total <= :maxTotal')
               ->setParameter('maxTotal', (float) $filters['max_total']);
        }

        if (!empty($filters['from_date'])) {
            $qb->andWhere('o.createdAt >= :fromDate')
               ->setParameter('fromDate', new \DateTime($filters['from_date']));
        }

        if (!empty($filters['to_date'])) {
            $qb->andWhere('o.createdAt <= :toDate')
               ->setParameter('toDate', new \DateTime($filters['to_date']));
        }

        if (!empty($filters['search'])) {
            $qb->andWhere('o.number LIKE :search OR o.notes LIKE :search')
               ->setParameter('search', '%' . $filters['search'] . '%');
        }

        return $qb;
    }

    public function applySorting(QueryBuilder $qb, ?string $sortBy, ?string $sortOrder): QueryBuilder
    {
        $sortBy = $sortBy ?? 'createdAt';
        $sortOrder = strtoupper($sortOrder ?? 'DESC');

        if (!in_array($sortBy, ['createdAt', 'total', 'status', 'number'])) {
            $sortBy = 'createdAt';
        }

        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'DESC';
        }

        $qb->orderBy('o.' . $sortBy, $sortOrder);

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
