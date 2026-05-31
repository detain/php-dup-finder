<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

class AnalyticsRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function getDailyActiveUsers(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $sql = "SELECT
                    DATE(created_at) as date,
                    COUNT(DISTINCT user_id) as dau,
                    COUNT(DISTINCT session_id) as sessions,
                    COUNT(*) as total_events
                FROM user_activities
                WHERE created_at BETWEEN :start_date AND :end_date
                GROUP BY DATE(created_at)
                ORDER BY date ASC";

        return $this->connection->fetchAllAssociative($sql, [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
        ]);
    }

    public function getMonthlyActiveUsers(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $sql = "SELECT
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(DISTINCT user_id) as mau,
                    COUNT(DISTINCT session_id) as sessions,
                    COUNT(*) as total_events
                FROM user_activities
                WHERE created_at BETWEEN :start_date AND :end_date
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month ASC";

        return $this->connection->fetchAllAssociative($sql, [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
        ]);
    }

    public function getHourlyActivityPattern(int $days = 30): array
    {
        $sql = "SELECT
                    HOUR(created_at) as hour,
                    COUNT(*) as event_count,
                    COUNT(DISTINCT user_id) as unique_users
                FROM user_activities
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY HOUR(created_at)
                ORDER BY hour ASC";

        return $this->connection->fetchAllAssociative($sql, ['days' => $days]);
    }

    public function getWeeklyActivityPattern(int $weeks = 12): array
    {
        $sql = "SELECT
                    DAYOFWEEK(created_at) as day_of_week,
                    COUNT(*) as event_count,
                    COUNT(DISTINCT user_id) as unique_users
                FROM user_activities
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL :weeks WEEK)
                GROUP BY DAYOFWEEK(created_at)
                ORDER BY day_of_week ASC";

        return $this->connection->fetchAllAssociative($sql, ['weeks' => $weeks]);
    }

    public function getRetentionCohort(\DateTimeInterface $startDate, int $cohortPeriodWeeks = 12): array
    {
        $sql = "WITH cohorts AS (
                    SELECT
                        user_id,
                        DATE(DATE_SUB(MIN(created_at), INTERVAL DAYOFWEEK(MIN(created_at))-1 DAY)) as cohort_date
                    FROM user_activities
                    WHERE created_at BETWEEN :start_date AND DATE_ADD(:start_date, INTERVAL :cohort_weeks WEEK)
                    GROUP BY user_id
                ),
                activity AS (
                    SELECT
                        u.user_id,
                        DATE(DATE_SUB(u.created_at, INTERVAL DAYOFWEEK(u.created_at)-1 DAY)) as activity_week,
                        c.cohort_date
                    FROM user_activities u
                    JOIN cohorts c ON u.user_id = c.user_id
                    WHERE u.created_at BETWEEN :start_date AND DATE_ADD(:start_date, INTERVAL :cohort_weeks WEEK)
                )
                SELECT
                    c.cohort_date,
                    TIMESTAMPDIFF(WEEK, c.cohort_date, a.activity_week) as weeks_since_join,
                    COUNT(DISTINCT a.user_id) as active_users
                FROM cohorts c
                JOIN activity a ON c.user_id = a.user_id
                GROUP BY c.cohort_date, weeks_since_join
                ORDER BY c.cohort_date, weeks_since_join";

        return $this->connection->fetchAllAssociative($sql, [
            'start_date' => $startDate->format('Y-m-d'),
            'cohort_weeks' => $cohortPeriodWeeks,
        ]);
    }
}
