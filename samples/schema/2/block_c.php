<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Schema;

/**
 * Database table definition for orders.
 * This schema is duplicated from:
 * - Eloquent model: src/Domain/Orders/Entity/Order.php
 * - API DTOs: OrderCreateRequest, OrderResponse
 *
 * @see Order migration: 2024_01_15_000001_create_orders_table.php
 * @see OrderItem migration: 2024_01_15_000002_create_order_items_table.php
 */
class OrderTableSchema
{
    public const TABLE_NAME = 'orders';
    public const ITEMS_TABLE_NAME = 'order_items';

    public static function getCreateTableSQL(): string
    {
        return <<<SQL
CREATE TABLE orders (
    id CHAR(36) NOT NULL PRIMARY KEY,
    order_number VARCHAR(20) NOT NULL UNIQUE,
    customer_id CHAR(36) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    shipping_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    shipping_address_id CHAR(36) NOT NULL,
    billing_address_id CHAR(36) NOT NULL,
    payment_method_id CHAR(36) NOT NULL,
    notes TEXT NULL,
    coupon_code VARCHAR(50) NULL,
    tracking_number VARCHAR(100) NULL,
    shipped_at DATETIME NULL,
    delivered_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_customer_id (customer_id),
    INDEX idx_status (status),
    INDEX idx_order_number (order_number),
    INDEX idx_created_at (created_at),
    INDEX idx_tracking_number (tracking_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    id CHAR(36) NOT NULL PRIMARY KEY,
    order_id CHAR(36) NOT NULL,
    product_id CHAR(36) NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    sku VARCHAR(100) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(12, 2) NOT NULL,
    subtotal DECIMAL(12, 2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_order_id (order_id),
    INDEX idx_product_id (product_id),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    public static function getColumns(): array
    {
        return [
            'id' => ['type' => 'char', 'length' => 36, 'primary' => true],
            'order_number' => ['type' => 'varchar', 'length' => 20, 'unique' => true],
            'customer_id' => ['type' => 'char', 'length' => 36, 'index' => true],
            'status' => ['type' => 'varchar', 'length' => 20, 'default' => 'pending', 'index' => true],
            'subtotal' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'tax_amount' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'shipping_amount' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'discount_amount' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'total_amount' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'currency' => ['type' => 'char', 'length' => 3, 'default' => 'USD'],
            'shipping_address_id' => ['type' => 'char', 'length' => 36],
            'billing_address_id' => ['type' => 'char', 'length' => 36],
            'payment_method_id' => ['type' => 'char', 'length' => 36],
            'notes' => ['type' => 'text', 'nullable' => true],
            'coupon_code' => ['type' => 'varchar', 'length' => 50, 'nullable' => true],
            'tracking_number' => ['type' => 'varchar', 'length' => 100, 'nullable' => true, 'index' => true],
            'shipped_at' => ['type' => 'datetime', 'nullable' => true],
            'delivered_at' => ['type' => 'datetime', 'nullable' => true],
            'created_at' => ['type' => 'datetime'],
            'updated_at' => ['type' => 'datetime'],
        ];
    }

    public static function getOrderItemColumns(): array
    {
        return [
            'id' => ['type' => 'char', 'length' => 36, 'primary' => true],
            'order_id' => ['type' => 'char', 'length' => 36, 'index' => true, 'fk' => 'orders.id'],
            'product_id' => ['type' => 'char', 'length' => 36, 'index' => true],
            'product_name' => ['type' => 'varchar', 'length' => 255],
            'sku' => ['type' => 'varchar', 'length' => 100],
            'quantity' => ['type' => 'int'],
            'unit_price' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'subtotal' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'created_at' => ['type' => 'datetime'],
        ];
    }
}
