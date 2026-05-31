<?php

declare(strict_types=1);

namespace App\Domain\Shared\Schema;

/**
 * Centralized Order schema registry.
 * Single source of truth for all Order schema definitions.
 */
final class OrderSchemaRegistry
{
    public const TABLE_NAME = 'orders';
    public const ITEMS_TABLE_NAME = 'order_items';

    public static function getColumns(): array
    {
        return [
            'id' => ['type' => 'char', 'length' => 36],
            'order_number' => ['type' => 'varchar', 'length' => 20],
            'customer_id' => ['type' => 'char', 'length' => 36],
            'status' => ['type' => 'varchar', 'length' => 20],
            'subtotal' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'tax_amount' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'shipping_amount' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'discount_amount' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'total_amount' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'currency' => ['type' => 'char', 'length' => 3],
        ];
    }

    public static function getDDL(): string
    {
        return TableBuilder::create(self::TABLE_NAME, self::getColumns());
    }
}
