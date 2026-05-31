<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

class ProductRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function searchProducts(string $query, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->select('p.id', 'p.sku', 'p.name', 'p.price', 'p.status', 'p.inventory_count')
           ->from('products', 'p')
           ->where('p.deleted_at IS NULL')
           ->andWhere($qb->expr()->or(
               $qb->expr()->like('p.name', ':query'),
               $qb->expr()->like('p.sku', ':query'),
               $qb->expr()->like('p.description', ':query'),
               $qb->expr()->like('p.id', ':query')
           ))
           ->setParameter('query', '%' . $query . '%')
           ->orderBy('p.name', 'ASC')
           ->setMaxResults($limit)
           ->setFirstResult($offset);

        if (isset($filters['status'])) {
            $qb->andWhere('p.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (isset($filters['category_id'])) {
            $qb->join('p', 'product_categories', 'pc', 'p.id = pc.product_id')
               ->andWhere('pc.category_id = :category_id')
               ->setParameter('category_id', $filters['category_id']);
        }

        if (isset($filters['manufacturer_id'])) {
            $qb->andWhere('p.manufacturer_id = :manufacturer_id')
               ->setParameter('manufacturer_id', $filters['manufacturer_id']);
        }

        if (isset($filters['price_min'])) {
            $qb->andWhere('p.price >= :price_min')
               ->setParameter('price_min', $filters['price_min']);
        }

        if (isset($filters['price_max'])) {
            $qb->andWhere('p.price <= :price_max')
               ->setParameter('price_max', $filters['price_max']);
        }

        if (isset($filters['in_stock'])) {
            $qb->andWhere('p.inventory_count ' . ($filters['in_stock'] ? '> 0' : '= 0'));
        }

        return $qb->execute()->fetchAll();
    }

    public function countSearchResults(string $query, array $filters = []): int
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->select('COUNT(DISTINCT p.id)')
           ->from('products', 'p')
           ->where('p.deleted_at IS NULL')
           ->andWhere($qb->expr()->or(
               $qb->expr()->like('p.name', ':query'),
               $qb->expr()->like('p.sku', ':query'),
               $qb->expr()->like('p.description', ':query'),
               $qb->expr()->like('p.id', ':query')
           ))
           ->setParameter('query', '%' . $query . '%');

        if (isset($filters['status'])) {
            $qb->andWhere('p.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (isset($filters['category_id'])) {
            $qb->join('p', 'product_categories', 'pc', 'p.id = pc.product_id')
               ->andWhere('pc.category_id = :category_id')
               ->setParameter('category_id', $filters['category_id']);
        }

        if (isset($filters['manufacturer_id'])) {
            $qb->andWhere('p.manufacturer_id = :manufacturer_id')
               ->setParameter('manufacturer_id', $filters['manufacturer_id']);
        }

        return (int) $qb->execute()->fetchColumn();
    }
}
