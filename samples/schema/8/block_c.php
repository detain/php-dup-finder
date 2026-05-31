<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics\Warehouse;

/**
 * Data warehouse schema for analytics events.
 * This schema is duplicated from:
 * - Doctrine entity: AnalyticsEvent
 * - Database table: analytics_events
 * - Event pipeline schemas
 * - BI tool schemas
 */
class AnalyticsWarehouseSchema
{
    public const TABLE_NAME = 'fact_events';
    public const SESSIONS_TABLE = 'dim_sessions';
    public const USERS_TABLE = 'dim_users';

    /**
     * Star schema for analytics events in the data warehouse.
     */
    public static function getCreateTableSQL(): string
    {
        return <<<SQL
CREATE TABLE fact_events (
    event_key BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id CHAR(36) NOT NULL,
    event_type_key INT NOT NULL,
    user_key INT NULL,
    session_key INT NOT NULL,
    date_key INT NOT NULL,
    entity_type_key INT NULL,
    entity_key BIGINT NULL,
    event_value DECIMAL(12, 4) NULL,
    properties JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    referrer VARCHAR(500) NULL,
    page_url VARCHAR(500) NULL,
    page_title VARCHAR(255) NULL,
    utm_source VARCHAR(100) NULL,
    utm_medium VARCHAR(100) NULL,
    utm_campaign VARCHAR(255) NULL,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,

    INDEX idx_event_id (event_id),
    INDEX idx_user_key (user_key),
    INDEX idx_session_key (session_key),
    INDEX idx_date_key (date_key),
    INDEX idx_occurred_at (occurred_at),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dim_event_types (
    event_type_key INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL UNIQUE,
    event_category VARCHAR(100) NULL,
    description TEXT NULL,
    is_transactional BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dim_sessions (
    session_key INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36) NOT NULL UNIQUE,
    user_key INT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    duration_seconds INT NULL,
    page_view_count INT NOT NULL DEFAULT 0,
    event_count INT NOT NULL DEFAULT 0,
    is_bounce BOOLEAN NOT NULL DEFAULT FALSE,
    entrance_page VARCHAR(500) NULL,
    exit_page VARCHAR(500) NULL,
    device_type VARCHAR(50) NULL,
    browser VARCHAR(100) NULL,
    operating_system VARCHAR(100) NULL,
    country VARCHAR(100) NULL,
    region VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dim_users (
    user_key INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NOT NULL UNIQUE,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    total_sessions INT NOT NULL DEFAULT 0,
    total_events INT NOT NULL DEFAULT 0,
    lifetime_value DECIMAL(12, 2) NULL,
    user_segment VARCHAR(50) NULL,
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
SQL;
    }

    /**
     * Predefined analytics queries.
     */
    public static function getReportingQueries(): array
    {
        return [
            'daily_active_users' => <<<SQL
SELECT
    d.date,
    COUNT(DISTINCT f.user_key) as dau,
    COUNT(DISTINCT s.session_key) as sessions,
    SUM(f.event_value) as total_value
FROM fact_events f
JOIN dim_dates d ON f.date_key = d.date_key
JOIN dim_sessions s ON f.session_key = s.session_key
WHERE f.user_key IS NOT NULL
GROUP BY d.date
ORDER BY d.date DESC
SQL,
            'event_funnel' => <<<SQL
SELECT
    e.event_type,
    COUNT(*) as event_count,
    COUNT(DISTINCT f.user_key) as unique_users
FROM fact_events f
JOIN dim_event_types e ON f.event_type_key = e.event_type_key
GROUP BY e.event_type
ORDER BY event_count DESC
SQL,
            'session_metrics' => <<<SQL
SELECT
    d.date,
    AVG(s.duration_seconds) as avg_session_duration,
    AVG(s.page_view_count) as avg_pages_per_session,
    COUNT(s.session_key) as total_sessions,
    SUM(s.is_bounce) / COUNT(s.session_key) as bounce_rate
FROM dim_sessions s
JOIN dim_dates d ON DATE(s.start_time) = d.date
GROUP BY d.date
ORDER BY d.date DESC
SQL,
        ];
    }
}
