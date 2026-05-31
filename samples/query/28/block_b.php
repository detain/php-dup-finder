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

    public function getInventoryReport(): array
    {
        $sql = "SELECT
                    p.id as product_id,
                    p.sku,
                    p.name as product_name,
                    p.inventory_count,
                    p.low_stock_threshold,
                    p.reorder_point,
                    p.reorder_quantity,
                    w.name as warehouse_name,
                    il.quantity as warehouse_quantity,
                    il.reserved_quantity,
                    (il.quantity - il.reserved_quantity) as available_quantity
                FROM products p
                LEFT JOIN inventory_levels il ON p.id = il.product_id
                LEFT JOIN warehouses w ON il.warehouse_id = w.id
                WHERE p.deleted_at IS NULL
                AND p.inventory_tracked = 1
                ORDER BY (il.quantity - il.reserved_quantity) ASC, p.name ASC";

        return $this->connection->fetchAllAssociative($sql);
    }

    public function getLowStockReport(int $threshold = 10): array
    {
        $sql = "SELECT
                    p.id as product_id,
                    p.sku,
                    p.name as product_name,
                    p.inventory_count,
                    p.low_stock_threshold,
                    COALESCE(SUM(il.quantity - il.reserved_quantity), 0) as available_quantity,
                    p.reorder_quantity,
                    s.name as supplier_name,
                    s.lead_time_days
                FROM products p
                LEFT JOIN inventory_levels il ON p.id = il.product_id
                LEFT JOIN suppliers s ON p.supplier_id = s.id
                WHERE p.deleted_at IS NULL
                AND p.inventory_tracked = 1
                AND p.status = 'active'
                GROUP BY p.id, p.sku, p.name, p.inventory_count, p.low_stock_threshold,
                         p.reorder_point, p.reorder_quantity, s.name, s.lead_time_days
                HAVING available_quantity <= :threshold
                ORDER BY available_quantity ASC";

        return $this->connection->fetchAllAssociative($sql, ['threshold' => $threshold]);
    }

    public function getInventoryValuationReport(): array
    {
        $sql = "SELECT
                    cat.id as category_id,
                    cat.name as category_name,
                    COUNT(DISTINCT p.id) as product_count,
                    SUM(p.inventory_count * p.cost) as total_cost_value,
                    SUM(p.inventory_count * p.price) as total_retail_value,
                    AVG(p.cost) as average_cost,
                    AVG(p.price) as average_price
                FROM categories cat
                LEFT JOIN products p ON cat.id = p.category_id
                WHERE p.deleted_at IS NULL
                AND p.inventory_tracked = 1
                AND p.status = 'active'
                GROUP BY cat.id, cat.name
                ORDER BY total_cost_value DESC";

        return $this->connection->fetchAllAssociative($sql);
    }

    public function getWarehouseUtilizationReport(): array
    {
        $sql = "SELECT
                    w.id as warehouse_id,
                    w.name as warehouse_name,
                    w.capacity as total_capacity,
                    w.current_utilization,
                    (w.capacity - w.current_utilization) as available_capacity,
                    ROUND((w.current_utilization / w.capacity) * 100, 2) as utilization_percentage,
                    COUNT(DISTINCT il.product_id) as unique_products,
                    SUM(il.quantity) as total_units,
                    SUM(il.reserved_quantity) as total_reserved
                FROM warehouses w
                LEFT JOIN inventory_levels il ON w.id = il.warehouse_id
                GROUP BY w.id, w.name, w.capacity, w.current_utilization
                ORDER BY utilization_percentage DESC";

        return $this->connection->fetchAllAssociative($sql);
    }

    public function getSupplierPerformanceReport(): array
    {
        $sql = "SELECT
                    s.id as supplier_id,
                    s.name as supplier_name,
                    s.code as supplier_code,
                    COUNT(DISTINCT p.id) as product_count,
                    SUM(p.inventory_count) as total_units_supplied,
                    SUM(p.inventory_count * p.cost) as total_value,
                    AVG(p.cost) as average_cost,
                    MIN(p.cost) as lowest_cost,
                    MAX(p.cost) as highest_cost,
                    s.lead_time_days,
                    s.on_time_delivery_rate
                FROM suppliers s
                LEFT JOIN products p ON s.id = p.supplier_id AND p.deleted_at IS NULL
                WHERE s.status = 'active'
                GROUP BY s.id, s.name, s.code, s.lead_time_days, s.on_time_delivery_rate
                ORDER BY total_value DESC";

        return $this->connection->fetchAllAssociative($sql);
    }
}
