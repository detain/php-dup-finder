<?php

declare(strict_types=1);

namespace App\Domain\Shared\Schema;

/**
 * Centralized Payment Transaction schema registry.
 * Single source of truth for all payment schema definitions.
 */
final class PaymentTransactionSchemaRegistry
{
    public const TABLE_NAME = 'payment_transactions';
    public const REPORTING_TABLE = 'fact_payment_transactions';
    public const GATEWAY_SCHEMA = 'PaymentIntent';

    public static function getDatabaseColumns(): array
    {
        return [
            'id' => ['type' => 'char', 'length' => 36, 'primary' => true],
            'customer_id' => ['type' => 'char', 'length' => 36],
            'order_id' => ['type' => 'char', 'length' => 36, 'nullable' => true],
            'amount' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'currency' => ['type' => 'char', 'length' => 3],
            'status' => ['type' => 'varchar', 'length' => 20],
            'payment_method' => ['type' => 'varchar', 'length' => 50],
            'gateway_transaction_id' => ['type' => 'varchar', 'length' => 100, 'nullable' => true],
            'gateway_response_code' => ['type' => 'varchar', 'length' => 100, 'nullable' => true],
            'created_at' => ['type' => 'datetime'],
            'processed_at' => ['type' => 'datetime', 'nullable' => true],
        ];
    }

    public static function getGatewaySchema(): array
    {
        return [
            'amount' => ['type' => 'integer'],
            'currency' => ['type' => 'string'],
            'payment_method' => ['type' => 'string'],
        ];
    }

    public static function getWebhookSchema(): array
    {
        return [
            'id' => ['type' => 'string'],
            'type' => ['type' => 'string'],
            'data' => ['type' => 'object'],
        ];
    }
}
