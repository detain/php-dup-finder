<?php

declare(strict_types=1);

namespace App\Filter;

use App\Entity\Product;
use Doctrine\ORM\QueryBuilder;

final class ProductFilter
{
    public function apply(QueryBuilder $qb, array $filters): QueryBuilder
    {
        if (!empty($filters['category_id'])) {
            $qb->andWhere('p.categoryId = :categoryId')
               ->setParameter('categoryId', $filters['category_id']);
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('p.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['min_price'])) {
            $qb->andWhere('p.price >= :minPrice')
               ->setParameter('minPrice', (float) $filters['min_price']);
        }

        if (!empty($filters['max_price'])) {
            $qb->andWhere('p.price <= :maxPrice')
               ->setParameter('maxPrice', (float) $filters['max_price']);
        }

        if (isset($filters['in_stock'])) {
            $inStock = filter_var($filters['in_stock'], FILTER_VALIDATE_BOOLEAN);
            if ($inStock) {
                $qb->andWhere('p.stock > 0');
            }
        }

        if (!empty($filters['search'])) {
            $qb->andWhere('p.name LIKE :search OR p.sku LIKE :search OR p.description LIKE :search')
               ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (!empty($filters['tags'])) {
            $tags = is_array($filters['tags']) ? $filters['tags'] : explode(',', $filters['tags']);
            $qb->andWhere('p.tags LIKE :tags')
               ->setParameter('tags', '%' . implode('%', $tags) . '%');
        }

        return $qb;
    }

    public function applySorting(QueryBuilder $qb, ?string $sortBy, ?string $sortOrder): QueryBuilder
    {
        $sortBy = $sortBy ?? 'createdAt';
        $sortOrder = strtoupper($sortOrder ?? 'DESC');

        if (!in_array($sortBy, ['createdAt', 'price', 'stock', 'name', 'sku'])) {
            $sortBy = 'createdAt';
        }

        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'DESC';
        }

        $qb->orderBy('p.' . $sortBy, $sortOrder);

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
