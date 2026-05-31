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

    public function bulkUpdatePrice(array $productIds, int $priceChange, string $operator = '+'): int
    {
        if (empty($productIds)) {
            return 0;
        }

        $sql = "UPDATE products SET price = price {$operator} :price_change, updated_at = :updated_at WHERE id IN (:ids) AND deleted_at IS NULL";

        $affectedRows = $this->connection->executeStatement(
            $sql,
            [
                'price_change' => $priceChange,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'ids' => $productIds,
            ],
            [
                'ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ]
        );

        return $affectedRows;
    }

    public function bulkUpdateStatus(array $productIds, string $status): int
    {
        if (empty($productIds)) {
            return 0;
        }

        $sql = 'UPDATE products SET status = :status, updated_at = :updated_at WHERE id IN (:ids) AND deleted_at IS NULL';

        $affectedRows = $this->connection->executeStatement(
            $sql,
            [
                'status' => $status,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'ids' => $productIds,
            ],
            [
                'ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ]
        );

        return $affectedRows;
    }

    public function bulkUpdateCategory(array $productIds, int $categoryId): int
    {
        if (empty($productIds)) {
            return 0;
        }

        $sql = 'UPDATE products SET category_id = :category_id, updated_at = :updated_at WHERE id IN (:ids) AND deleted_at IS NULL';

        $affectedRows = $this->connection->executeStatement(
            $sql,
            [
                'category_id' => $categoryId,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'ids' => $productIds,
            ],
            [
                'ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ]
        );

        return $affectedRows;
    }

    public function bulkDelete(array $productIds): int
    {
        if (empty($productIds)) {
            return 0;
        }

        $sql = 'UPDATE products SET deleted_at = :deleted_at WHERE id IN (:ids) AND deleted_at IS NULL';

        $affectedRows = $this->connection->executeStatement(
            $sql,
            [
                'deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'ids' => $productIds,
            ],
            [
                'ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ]
        );

        return $affectedRows;
    }

    public function bulkAddCategories(array $productIds, array $categoryIds): int
    {
        if (empty($productIds) || empty($categoryIds)) {
            return 0;
        }

        $insertValues = [];
        $params = [];
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($productIds as $pIndex => $productId) {
            foreach ($categoryIds as $cIndex => $categoryId) {
                $insertValues[] = "(:product_id_{$pIndex}_{$cIndex}, :category_id_{$pIndex}_{$cIndex}, :created_at)";
                $params["product_id_{$pIndex}_{$cIndex}"] = $productId;
                $params["category_id_{$pIndex}_{$cIndex}"] = $categoryId;
                $params["created_at"] = $now;
            }
        }

        $sql = 'INSERT INTO product_categories (product_id, category_id, created_at) VALUES ' . implode(', ', $insertValues);

        $this->connection->executeStatement($sql, $params);

        return count($productIds) * count($categoryIds);
    }

    public function bulkRemoveCategories(array $productIds, array $categoryIds): int
    {
        if (empty($productIds) || empty($categoryIds)) {
            return 0;
        }

        $sql = 'DELETE FROM product_categories WHERE product_id IN (:product_ids) AND category_id IN (:category_ids)';

        $affectedRows = $this->connection->executeStatement(
            $sql,
            [
                'product_ids' => $productIds,
                'category_ids' => $categoryIds,
            ],
            [
                'product_ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
                'category_ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ]
        );

        return $affectedRows;
    }
}
