<?php
declare(strict_types=1);

namespace Analytics\Reporting;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;

final class ReportGenerationService
{
    private const CACHE_TTL = 900;

    public function __construct(
        private readonly Connection $database,
        private readonly LoggerInterface $logger,
        private readonly ReportStorage $storage
    ) {}

    public function generateSalesReport(string $startDate, string $endDate): Report
    {
        $cacheKey = 'sales_report:' . md5($startDate . $endDate);

        // Try to get from cache
        try {
            $cached = $this->storage->get($cacheKey);
            if ($cached !== null) {
                $this->logger->debug('Sales report served from cache', [
                    'start' => $startDate,
                    'end' => $endDate
                ]);
                return unserialize($cached);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Cache unavailable for report generation', [
                'error' => $e->getMessage()
            ]);
        }

        // Generate from database
        $reportData = $this->buildSalesReportFromDatabase($startDate, $endDate);

        // Cache the result
        try {
            $this->storage->set($cacheKey, serialize($reportData), self::CACHE_TTL);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to cache report', [
                'error' => $e->getMessage()
            ]);
        }

        return $reportData;
    }

    public function getRealTimeMetrics(): array
    {
        $cacheKey = 'realtime_metrics';

        // Try cache
        try {
            $cached = $this->storage->get($cacheKey);
            if ($cached !== null) {
                return json_decode($cached, true);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Cache miss for real-time metrics', [
                'error' => $e->getMessage()
            ]);
        }

        // Fetch from metrics API
        try {
            $client = HttpClient::create();
            $response = $client->request('GET', $_ENV['METRICS_API_URL'] . '/realtime', [
                'timeout' => 5
            ]);

            $metrics = $response->toArray();

            try {
                $this->storage->set($cacheKey, json_encode($metrics), 60);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to cache real-time metrics');
            }

            return $metrics;

        } catch (\Exception $e) {
            $this->logger->error('Metrics API unavailable, using fallback data', [
                'error' => $e->getMessage()
            ]);

            // Return stale cached data if available
            try {
                $stale = $this->storage->get('realtime_metrics_stale');
                if ($stale !== null) {
                    return json_decode($stale, true);
                }
            } catch (\Exception $e) {
                $this->logger->warning('No stale metrics available');
            }

            // Ultimate fallback: database summary
            return $this->getDatabaseFallbackMetrics();
        }
    }

    public function getCustomerRetentionCohorts(string $quarter): array
    {
        $cacheKey = 'cohorts:' . $quarter;

        // Check cache
        try {
            $cached = apcu_fetch($cacheKey);
            if ($cached !== false) {
                return $cached;
            }
        } catch (\Exception $e) {
            $this->logger->warning('APCu unavailable for cohort calculation', [
                'error' => $e->getMessage()
            ]);
        }

        // Calculate from database
        $cohorts = $this->calculateCohortsFromDatabase($quarter);

        // Store in cache
        try {
            apcu_store($cacheKey, $cohorts, 3600);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to store cohorts in APCu', [
                'error' => $e->getMessage()
            ]);
        }

        return $cohorts;
    }

    private function buildSalesReportFromDatabase(string $startDate, string $endDate): array
    {
        $sql = <<<SQL
            SELECT
                DATE(created_at) as date,
                COUNT(*) as order_count,
                SUM(total) as revenue,
                AVG(total) as avg_order_value
            FROM orders
            WHERE created_at BETWEEN :start AND :end
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        SQL;

        return $this->database->fetchAllAssociative($sql, [
            'start' => $startDate,
            'end' => $endDate . ' 23:59:59'
        ]);
    }

    private function calculateCohortsFromDatabase(string $quarter): array
    {
        // Cohort calculation logic
        return [];
    }

    private function getDatabaseFallbackMetrics(): array
    {
        $sql = "SELECT COUNT(*) as total_orders, SUM(total) as total_revenue FROM orders WHERE created_at > NOW() - INTERVAL 24 HOUR";

        $result = $this->database->fetchAssociative($sql);

        return [
            'orders_today' => (int) ($result['total_orders'] ?? 0),
            'revenue_today' => (float) ($result['total_revenue'] ?? 0),
            'source' => 'database_fallback',
            'generated_at' => date('c')
        ];
    }
}
