<?php

declare(strict_types=1);

namespace App\Infrastructure\Reporting\Schema;

/**
 * Reporting database schema for payment transactions.
 * This schema is duplicated from:
 * - Doctrine entity: PaymentTransaction
 * - Payment gateway API
 * - Database table: payment_transactions
 * - Webhook payload schemas
 */
class PaymentReportingSchema
{
    public const TABLE_NAME = 'fact_payment_transactions';

    /**
     * Star schema for payment transaction reporting.
     * Mirrors payment_transactions with additional denormalized dimensions.
     */
    public static function getCreateTableSQL(): string
    {
        return <<<SQL
CREATE TABLE fact_payment_transactions (
    transaction_key BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transaction_id CHAR(36) NOT NULL,
    customer_key INT NOT NULL,
    order_key INT NULL,
    date_key INT NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    gateway VARCHAR(100) NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    currency CHAR(3) NOT NULL,
    status VARCHAR(20) NOT NULL,
    is_successful BOOLEAN NOT NULL,
    is_refunded BOOLEAN NOT NULL,
    refund_amount DECIMAL(12, 2) NULL,
    processing_time_ms INT NULL,
    gateway_response_code VARCHAR(100) NULL,
    created_at DATETIME NOT NULL,
    processed_at DATETIME NULL,

    INDEX idx_transaction_id (transaction_id),
    INDEX idx_customer_key (customer_key),
    INDEX idx_order_key (order_key),
    INDEX idx_date_key (date_key),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_is_successful (is_successful),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dim_payment_methods (
    method_key INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    method_id VARCHAR(50) NOT NULL UNIQUE,
    method_name VARCHAR(100) NOT NULL,
    method_type VARCHAR(50) NOT NULL,
    is_card BOOLEAN NOT NULL,
    is_bank_transfer BOOLEAN NOT NULL,
    is_digital_wallet BOOLEAN NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dim_dates (
    date_key INT NOT NULL PRIMARY KEY,
    date DATE NOT NULL,
    day_of_week INT NOT NULL,
    day_name VARCHAR(20) NOT NULL,
    day_of_month INT NOT NULL,
    day_of_year INT NOT NULL,
    week_of_year INT NOT NULL,
    month INT NOT NULL,
    month_name VARCHAR(20) NOT NULL,
    quarter INT NOT NULL,
    year INT NOT NULL,
    is_weekend BOOLEAN NOT NULL,
    is_holiday BOOLEAN NOT NULL DEFAULT FALSE,
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fact_refunds (
    refund_key BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    refund_id CHAR(36) NOT NULL,
    original_transaction_key BIGINT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    currency CHAR(3) NOT NULL,
    reason VARCHAR(50) NOT NULL,
    status VARCHAR(20) NOT NULL,
    requested_at DATETIME NOT NULL,
    processed_at DATETIME NULL,

    INDEX idx_refund_id (refund_id),
    INDEX idx_original_transaction_key (original_transaction_key),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    /**
     * Predefined reporting queries.
     */
    public static function getReportingQueries(): array
    {
        return [
            'daily_revenue' => <<<SQL
SELECT
    d.date,
    d.day_name,
    COUNT(DISTINCT f.transaction_id) as transaction_count,
    SUM(f.amount) as total_revenue,
    AVG(f.amount) as average_transaction_value
FROM fact_payment_transactions f
JOIN dim_dates d ON f.date_key = d.date_key
WHERE f.is_successful = true
GROUP BY d.date, d.day_name
ORDER BY d.date DESC
SQL,
            'refund_rate_by_method' => <<<SQL
SELECT
    pm.method_name,
    COUNT(*) as total_transactions,
    SUM(CASE WHEN f.is_refunded THEN 1 ELSE 0 END) as refund_count,
    AVG(CASE WHEN f.is_refunded THEN f.refund_amount END) as avg_refund_amount,
    AVG(CASE WHEN f.is_refunded THEN 1.0 ELSE 0.0 END) as refund_rate
FROM fact_payment_transactions f
JOIN dim_payment_methods pm ON f.payment_method = pm.method_id
GROUP BY pm.method_name
SQL,
            'payment_method_distribution' => <<<SQL
SELECT
    pm.method_name,
    COUNT(*) as transaction_count,
    SUM(f.amount) as total_volume,
    AVG(f.amount) as average_value
FROM fact_payment_transactions f
JOIN dim_payment_methods pm ON f.payment_method = pm.method_id
WHERE f.is_successful = true
GROUP BY pm.method_name
ORDER BY total_volume DESC
SQL,
            'processing_time_trend' => <<<SQL
SELECT
    d.date,
    AVG(f.processing_time_ms) as avg_processing_time_ms,
    MAX(f.processing_time_ms) as max_processing_time_ms,
    PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY f.processing_time_ms) as p95_processing_time_ms
FROM fact_payment_transactions f
JOIN dim_dates d ON f.date_key = d.date_key
WHERE f.is_successful = true AND f.processing_time_ms IS NOT NULL
GROUP BY d.date
ORDER BY d.date DESC
SQL,
        ];
    }
}
