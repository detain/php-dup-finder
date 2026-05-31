<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

class OrderRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function getSalesReport(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        ?int $groupByDay = null
    ): array {
        $params = [
            'start_date' => $startDate->format('Y-m-d 00:00:00'),
            'end_date' => $endDate->format('Y-m-d 23:59:59'),
        ];

        if ($groupByDay) {
            $dateGrouping = 'DATE(o.created_at)';
        } else {
            $dateGrouping = 'MONTH(o.created_at)';
        }

        $sql = "SELECT
                    {$dateGrouping} as date_period,
                    COUNT(DISTINCT o.id) as order_count,
                    COUNT(DISTINCT o.customer_id) as unique_customers,
                    SUM(o.total) as total_revenue,
                    AVG(o.total) as average_order_value,
                    MIN(o.total) as smallest_order,
                    MAX(o.total) as largest_order,
                    SUM(o.items_count) as total_items_sold
                FROM orders o
                WHERE o.status NOT IN ('cancelled', 'refunded')
                AND o.created_at BETWEEN :start_date AND :end_date
                GROUP BY {$dateGrouping}
                ORDER BY {$dateGrouping} ASC";

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    public function getProductSalesReport(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        int $limit = 50
    ): array {
        $sql = "SELECT
                    p.id as product_id,
                    p.sku,
                    p.name as product_name,
                    COUNT(DISTINCT oi.order_id) as order_count,
                    SUM(oi.quantity) as units_sold,
                    SUM(oi.total) as revenue,
                    AVG(oi.unit_price) as average_price
                FROM product_order_items oi
                JOIN products p ON oi.product_id = p.id
                JOIN orders o ON oi.order_id = o.id
                WHERE o.status NOT IN ('cancelled', 'refunded')
                AND o.created_at BETWEEN :start_date AND :end_date
                GROUP BY p.id, p.sku, p.name
                ORDER BY revenue DESC
                LIMIT :limit";

        return $this->connection->fetchAllAssociative($sql, [
            'start_date' => $startDate->format('Y-m-d 00:00:00'),
            'end_date' => $endDate->format('Y-m-d 23:59:59'),
            'limit' => $limit,
        ]);
    }

    public function getCustomerSalesReport(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        int $limit = 50
    ): array {
        $sql = "SELECT
                    c.id as customer_id,
                    c.email as customer_email,
                    c.name as customer_name,
                    COUNT(DISTINCT o.id) as order_count,
                    SUM(o.total) as lifetime_value,
                    AVG(o.total) as average_order_value,
                    MAX(o.created_at) as last_order_date,
                    MIN(o.created_at) as first_order_date
                FROM customers c
                JOIN orders o ON c.id = o.customer_id
                WHERE o.status NOT IN ('cancelled', 'refunded')
                AND o.created_at BETWEEN :start_date AND :end_date
                GROUP BY c.id, c.email, c.name
                ORDER BY lifetime_value DESC
                LIMIT :limit";

        return $this->connection->fetchAllAssociative($sql, [
            'start_date' => $startDate->format('Y-m-d 00:00:00'),
            'end_date' => $endDate->format('Y-m-d 23:59:59'),
            'limit' => $limit,
        ]);
    }

    public function getCategorySalesReport(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate
    ): array {
        $sql = "SELECT
                    cat.id as category_id,
                    cat.name as category_name,
                    COUNT(DISTINCT oi.order_id) as order_count,
                    SUM(oi.quantity) as units_sold,
                    SUM(oi.total) as revenue
                FROM categories cat
                JOIN product_categories pc ON cat.id = pc.category_id
                JOIN product_order_items oi ON pc.product_id = oi.product_id
                JOIN orders o ON oi.order_id = o.id
                WHERE o.status NOT IN ('cancelled', 'refunded')
                AND o.created_at BETWEEN :start_date AND :end_date
                GROUP BY cat.id, cat.name
                ORDER BY revenue DESC";

        return $this->connection->fetchAllAssociative($sql, [
            'start_date' => $startDate->format('Y-m-d 00:00:00'),
            'end_date' => $endDate->format('Y-m-d 23:59:59'),
        ]);
    }
}
