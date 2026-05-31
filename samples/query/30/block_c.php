<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;
use DateTimeInterface;

trait TimeSeriesTrait
{
    abstract protected function getTimeSeriesTable(): string;

    abstract protected function getTimeSeriesDateColumn(): string;

    protected function getDailyTimeSeries(
        Connection $connection,
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $aggregations = []
    ): array {
        $table = $this->getTimeSeriesTable();
        $dateCol = $this->getTimeSeriesDateColumn();

        $selectParts = ["DATE({$dateCol}) as date"];
        foreach ($aggregations as $alias => $expr) {
            $selectParts[] = "{$expr} as {$alias}";
        }

        $sql = "SELECT " . implode(', ', $selectParts) . "
                FROM {$table}
                WHERE {$dateCol} BETWEEN :start_date AND :end_date
                GROUP BY DATE({$dateCol})
                ORDER BY date ASC";

        return $connection->fetchAllAssociative($sql, [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
        ]);
    }

    protected function getHourlyPattern(
        Connection $connection,
        int $lookbackDays = 30
    ): array {
        $table = $this->getTimeSeriesTable();
        $dateCol = $this->getTimeSeriesDateColumn();

        $sql = "SELECT
                    HOUR({$dateCol}) as hour,
                    COUNT(*) as event_count
                FROM {$table}
                WHERE {$dateCol} >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY HOUR({$dateCol})
                ORDER BY hour ASC";

        return $connection->fetchAllAssociative($sql, ['days' => $lookbackDays]);
    }

    protected function getDayOfWeekPattern(
        Connection $connection,
        int $lookbackWeeks = 12
    ): array {
        $table = $this->getTimeSeriesTable();
        $dateCol = $this->getTimeSeriesDateColumn();

        $sql = "SELECT
                    DAYOFWEEK({$dateCol}) as day_of_week,
                    COUNT(*) as event_count
                FROM {$table}
                WHERE {$dateCol} >= DATE_SUB(NOW(), INTERVAL :weeks WEEK)
                GROUP BY DAYOFWEEK({$dateCol})
                ORDER BY day_of_week ASC";

        return $connection->fetchAllAssociative($sql, ['weeks' => $lookbackWeeks]);
    }
}

class AnalyticsRepository
{
    use TimeSeriesTrait;

    protected function getTimeSeriesTable(): string
    {
        return 'user_activities';
    }

    protected function getTimeSeriesDateColumn(): string
    {
        return 'created_at';
    }

    public function getDailyActiveUsers(DateTimeInterface $startDate, DateTimeInterface $endDate): array
    {
        return $this->getDailyTimeSeries($this->connection, $startDate, $endDate, [
            'dau' => 'COUNT(DISTINCT user_id)',
            'sessions' => 'COUNT(DISTINCT session_id)',
        ]);
    }
}
