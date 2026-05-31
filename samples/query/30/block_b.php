<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

class RevenueRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function getDailyRevenue(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $sql = "SELECT
                    DATE(created_at) as date,
                    COUNT(DISTINCT id) as transaction_count,
                    SUM(total) as gross_revenue,
                    SUM(total - cost) as net_revenue,
                    AVG(total) as average_transaction_value,
                    MIN(total) as smallest_transaction,
                    MAX(total) as largest_transaction
                FROM orders
                WHERE status NOT IN ('cancelled', 'refunded')
                AND created_at BETWEEN :start_date AND :end_date
                GROUP BY DATE(created_at)
                ORDER BY date ASC";

        return $this->connection->fetchAllAssociative($sql, [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
        ]);
    }

    public function getMonthlyRevenue(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $sql = "SELECT
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(DISTINCT id) as transaction_count,
                    SUM(total) as gross_revenue,
                    SUM(total - cost) as net_revenue,
                    AVG(total) as average_transaction_value
                FROM orders
                WHERE status NOT IN ('cancelled', 'refunded')
                AND created_at BETWEEN :start_date AND :end_date
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month ASC";

        return $this->connection->fetchAllAssociative($sql, [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
        ]);
    }

    public function getHourlyRevenuePattern(int $days = 30): array
    {
        $sql = "SELECT
                    HOUR(created_at) as hour,
                    COUNT(*) as transaction_count,
                    SUM(total) as revenue
                FROM orders
                WHERE status NOT IN ('cancelled', 'refunded')
                AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY HOUR(created_at)
                ORDER BY hour ASC";

        return $this->connection->fetchAllAssociative($sql, ['days' => $days]);
    }

    public function getWeeklyRevenuePattern(int $weeks = 12): array
    {
        $sql = "SELECT
                    DAYOFWEEK(created_at) as day_of_week,
                    COUNT(*) as transaction_count,
                    SUM(total) as revenue,
                    AVG(total) as average_transaction
                FROM orders
                WHERE status NOT IN ('cancelled', 'refunded')
                AND created_at >= DATE_SUB(NOW(), INTERVAL :weeks WEEK)
                GROUP BY DAYOFWEEK(created_at)
                ORDER BY day_of_week ASC";

        return $this->connection->fetchAllAssociative($sql, ['weeks' => $weeks]);
    }

    public function getRevenueGrowth(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $sql = "WITH daily_revenue AS (
                    SELECT
                        DATE(created_at) as date,
                        SUM(total) as daily_total
                    FROM orders
                    WHERE status NOT IN ('cancelled', 'refunded')
                    AND created_at BETWEEN :start_date AND :end_date
                    GROUP BY DATE(created_at)
                )
                SELECT
                    dr1.date,
                    dr1.daily_total as revenue,
                    dr2.date as previous_date,
                    dr2.daily_total as previous_revenue,
                    dr1.daily_total - dr2.daily_total as growth,
                    ((dr1.daily_total - dr2.daily_total) / dr2.daily_total) * 100 as growth_percentage
                FROM daily_revenue dr1
                LEFT JOIN daily_revenue dr2 ON dr2.date = DATE_SUB(dr1.date, INTERVAL 1 DAY)
                ORDER BY dr1.date ASC";

        return $this->connection->fetchAllAssociative($sql, [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
        ]);
    }
}
