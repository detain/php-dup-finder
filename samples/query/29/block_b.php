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

    public function exportOrdersCsv(array $filters = []): array
    {
        $sql = "SELECT
                    o.id,
                    o.order_number,
                    o.customer_email,
                    o.status,
                    o.total,
                    o.currency,
                    o.payment_method,
                    o.shipping_address,
                    o.created_at,
                    o.updated_at,
                    COUNT(oi.id) as item_count
                FROM orders o
                LEFT JOIN order_items oi ON o.id = oi.order_id
                WHERE o.deleted_at IS NULL";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND o.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['customer_id'])) {
            $sql .= " AND o.customer_id = :customer_id";
            $params['customer_id'] = $filters['customer_id'];
        }

        if (!empty($filters['created_after'])) {
            $sql .= " AND o.created_at >= :created_after";
            $params['created_after'] = $filters['created_after'];
        }

        if (!empty($filters['created_before'])) {
            $sql .= " AND o.created_at <= :created_before";
            $params['created_before'] = $filters['created_before'];
        }

        if (!empty($filters['min_total'])) {
            $sql .= " AND o.total >= :min_total";
            $params['min_total'] = $filters['min_total'];
        }

        if (!empty($filters['max_total'])) {
            $sql .= " AND o.total <= :max_total";
            $params['max_total'] = $filters['max_total'];
        }

        $sql .= " GROUP BY o.id, o.order_number, o.customer_email, o.status,
                  o.total, o.currency, o.payment_method, o.shipping_address,
                  o.created_at, o.updated_at";

        $sql .= " ORDER BY o.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT :limit";
            $params['limit'] = $filters['limit'];
        }

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    public function exportOrdersJson(array $filters = []): array
    {
        $orders = $this->exportOrdersCsv($filters);

        return array_map(function ($order) {
            return [
                'id' => (int) $order['id'],
                'order_number' => $order['order_number'],
                'customer_email' => $order['customer_email'],
                'status' => $order['status'],
                'total' => (float) $order['total'],
                'currency' => $order['currency'],
                'payment_method' => $order['payment_method'],
                'item_count' => (int) $order['item_count'],
                'items' => $this->getOrderItems((int) $order['id']),
                'created_at' => $order['created_at'],
            ];
        }, $orders);
    }

    private function getOrderItems(int $orderId): array
    {
        $sql = "SELECT
                    oi.product_id,
                    p.sku,
                    p.name,
                    oi.quantity,
                    oi.unit_price,
                    oi.total
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = :order_id";

        return $this->connection->fetchAllAssociative($sql, ['order_id' => $orderId]);
    }
}
