<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;
use DateTimeInterface;

trait ReportAggregationTrait
{
    abstract protected function getReportTableName(): string;

    protected function buildDateRangeCondition(
        Connection $connection,
        string $alias,
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $excludeStatuses = []
    ): array {
        $params = [
            'start_date' => $startDate->format('Y-m-d 00:00:00'),
            'end_date' => $endDate->format('Y-m-d 23:59:59'),
        ];

        $conditions = ["{$alias}.created_at BETWEEN :start_date AND :end_date"];

        if (!empty($excludeStatuses)) {
            $placeholders = [];
            foreach ($excludeStatuses as $index => $status) {
                $key = "status_{$index}";
                $placeholders[] = ":{$key}";
                $params[$key] = $status;
            }
            $conditions[] = "{$alias}.status NOT IN (" . implode(', ', $placeholders) . ")";
        }

        return [$conditions, $params];
    }

    protected function buildDateGrouping(?int $groupByDay = null): string
    {
        return $groupByDay ? 'DATE(o.created_at)' : 'MONTH(o.created_at)';
    }

    protected function executeReport(
        Connection $connection,
        string $sql,
        array $params = []
    ): array {
        return $connection->fetchAllAssociative($sql, $params);
    }
}

class OrderRepository
{
    use ReportAggregationTrait;

    protected function getReportTableName(): string
    {
        return 'orders';
    }

    public function getSalesReport(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        ?int $groupByDay = null
    ): array {
        [$conditions, $params] = $this->buildDateRangeCondition(
            $this->connection,
            'o',
            $startDate,
            $endDate,
            ['cancelled', 'refunded']
        );

        $dateGrouping = $this->buildDateGrouping($groupByDay);

        $sql = "SELECT
                    {$dateGrouping} as date_period,
                    COUNT(DISTINCT o.id) as order_count,
                    SUM(o.total) as total_revenue
                FROM orders o
                WHERE " . implode(' AND ', $conditions) . "
                GROUP BY {$dateGrouping}";

        return $this->executeReport($this->connection, $sql, $params);
    }
}
