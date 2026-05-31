<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\AnalyticsRepository;
use App\Service\MetricsCollector;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class AnalyticsCacheHandler
{
    private const CACHE_PREFIX = 'analytics';
    private const DEFAULT_TTL = 300;
    private const STALE_TTL = 60;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly AnalyticsRepository $analyticsRepository,
        private readonly MetricsCollector $metricsCollector,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getDashboardMetrics(int $userId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildDashboardMetricsCacheKey($userId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'dashboard_metrics']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'dashboard_metrics']);

        $metrics = $this->analyticsRepository->getDashboardMetrics($userId);
        if ($metrics === null) {
            return null;
        }

        $data = $this->serializeDashboardMetrics($metrics);
        $this->setDashboardMetrics($userId, $data);
        return $data;
    }

    public function setDashboardMetrics(int $userId, array $data, ?int $ttl = null): void
    {
        $this->cache->set($this->buildDashboardMetricsCacheKey($userId), $data, $ttl ?? self::DEFAULT_TTL);
    }

    public function invalidateDashboardMetrics(int $userId): void
    {
        $this->cache->delete($this->buildDashboardMetricsCacheKey($userId));
    }

    public function refreshDashboardMetrics(int $userId): void
    {
        $metrics = $this->analyticsRepository->getDashboardMetrics($userId);
        if ($metrics === null) {
            $this->cache->delete($this->buildDashboardMetricsCacheKey($userId));
            return;
        }
        $this->setDashboardMetrics($userId, $this->serializeDashboardMetrics($metrics));
    }

    public function getTimeSeriesData(string $metricName, int $startTime, int $endTime, array $filters, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildTimeSeriesCacheKey($metricName, $startTime, $endTime, $filters);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'time_series']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'time_series']);

        $data = $this->analyticsRepository->getTimeSeriesData($metricName, $startTime, $endTime, $filters);
        if ($data === null) {
            return null;
        }

        $this->setTimeSeriesData($metricName, $startTime, $endTime, $filters, $data);
        return $data;
    }

    public function setTimeSeriesData(string $metricName, int $startTime, int $endTime, array $filters, array $data, ?int $ttl = null): void
    {
        $this->cache->set($this->buildTimeSeriesCacheKey($metricName, $startTime, $endTime, $filters), $data, $ttl ?? self::DEFAULT_TTL);
    }

    public function invalidateTimeSeriesData(string $metricName, int $startTime, int $endTime, array $filters): void
    {
        $this->cache->delete($this->buildTimeSeriesCacheKey($metricName, $startTime, $endTime, $filters));
    }

    public function getAggregation(string $metricName, string $aggregation, int $windowStart, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildAggregationCacheKey($metricName, $aggregation, $windowStart);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'aggregation']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'aggregation']);

        $agg = $this->analyticsRepository->getAggregation($metricName, $aggregation, $windowStart);
        if ($agg === null) {
            return null;
        }

        $data = $this->serializeAggregation($agg);
        $this->setAggregation($metricName, $aggregation, $windowStart, $data);
        return $data;
    }

    public function setAggregation(string $metricName, string $aggregation, int $windowStart, array $data, ?int $ttl = null): void
    {
        $this->cache->set($this->buildAggregationCacheKey($metricName, $aggregation, $windowStart), $data, $ttl ?? self::DEFAULT_TTL);
    }

    public function invalidateAggregation(string $metricName, string $aggregation, int $windowStart): void
    {
        $this->cache->delete($this->buildAggregationCacheKey($metricName, $aggregation, $windowStart));
    }

    public function getFunnelData(string $funnelName, int $startTime, int $endTime, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildFunnelCacheKey($funnelName, $startTime, $endTime);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'funnel']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'funnel']);

        $funnel = $this->analyticsRepository->getFunnelData($funnelName, $startTime, $endTime);
        if ($funnel === null) {
            return null;
        }

        $data = $this->serializeFunnelData($funnel);
        $this->setFunnelData($funnelName, $startTime, $endTime, $data);
        return $data;
    }

    public function setFunnelData(string $funnelName, int $startTime, int $endTime, array $data, ?int $ttl = null): void
    {
        $this->cache->set($this->buildFunnelCacheKey($funnelName, $startTime, $endTime), $data, $ttl ?? self::DEFAULT_TTL);
    }

    public function invalidateFunnelData(string $funnelName, int $startTime, int $endTime): void
    {
        $this->cache->delete($this->buildFunnelCacheKey($funnelName, $startTime, $endTime));
    }

    public function getReportData(string $reportName, array $params, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildReportCacheKey($reportName, $params);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'analytics_report']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'analytics_report']);

        $report = $this->analyticsRepository->getReportData($reportName, $params);
        if ($report === null) {
            return null;
        }

        $data = $this->serializeReportData($report);
        $this->setReportData($reportName, $params, $data);
        return $data;
    }

    public function setReportData(string $reportName, array $params, array $data, ?int $ttl = null): void
    {
        $this->cache->set($this->buildReportCacheKey($reportName, $params), $data, $ttl ?? self::DEFAULT_TTL);
    }

    public function invalidateReportData(string $reportName, array $params): void
    {
        $this->cache->delete($this->buildReportCacheKey($reportName, $params));
    }

    public function handleMetricUpdate(string $metricName): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, '*', $metricName, '*');
        $this->cache->deleteByPattern($pattern);
        $this->logger->info('Handled metric update cache invalidation', ['metric_name' => $metricName]);
    }

    public function handleReportChange(string $reportName): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'report', $reportName, '*');
        $this->cache->deleteByPattern($pattern);
        $this->logger->info('Handled report change cache invalidation', ['report_name' => $reportName]);
    }

    private function buildDashboardMetricsCacheKey(int $userId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'dashboard', (string) $userId);
    }

    private function buildTimeSeriesCacheKey(string $metricName, int $start, int $end, array $filters): string
    {
        ksort($filters);
        return $this->keyBuilder->build(
            self::CACHE_PREFIX, 'timeseries', $metricName,
            (string) $start, (string) $end, md5(json_encode($filters))
        );
    }

    private function buildAggregationCacheKey(string $metric, string $agg, int $window): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'agg', $metric, $agg, (string) $window);
    }

    private function buildFunnelCacheKey(string $funnel, int $start, int $end): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'funnel', $funnel, (string) $start, (string) $end);
    }

    private function buildReportCacheKey(string $report, array $params): string
    {
        ksort($params);
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'report', $report, md5(json_encode($params)));
    }

    private function serializeDashboardMetrics(array $metrics): array
    {
        return $metrics;
    }

    private function serializeAggregation(object $agg): array
    {
        return [
            'sum' => $agg->getSum(),
            'count' => $agg->getCount(),
            'avg' => $agg->getAvg(),
            'min' => $agg->getMin(),
            'max' => $agg->getMax(),
        ];
    }

    private function serializeFunnelData(array $funnel): array
    {
        return array_map(fn($step) => [
            'step_name' => $step['name'],
            'users' => $step['users'],
            'conversion_rate' => $step['conversion_rate'],
        ], $funnel);
    }

    private function serializeReportData(array $report): array
    {
        return $report;
    }
}
