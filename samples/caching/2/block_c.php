<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\MetricsRepository;
use App\Service\MetricsCollector;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class MetricsCacheHandler
{
    private const CACHE_PREFIX = 'metrics';
    private const DEFAULT_TTL = 60;
    private const AGGREGATE_TTL = 300;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly MetricsRepository $metricsRepository,
        private readonly MetricsCollector $metricsCollector,
        private readonly MetricsService $metricsService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getCounter(string $name, int $windowStart, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildCounterCacheKey($name, $windowStart);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metricsService->increment('cache.hit', ['type' => 'metrics_counter']);
            return $cached;
        }

        $this->metricsService->increment('cache.miss', ['type' => 'metrics_counter']);

        $counter = $this->metricsRepository->getCounter($name, $windowStart);

        if ($counter === null) {
            return null;
        }

        $data = $this->serializeCounter($counter);
        $this->setCounter($name, $windowStart, $data);

        return $data;
    }

    public function setCounter(string $name, int $windowStart, array $data): void
    {
        $cacheKey = $this->buildCounterCacheKey($name, $windowStart);

        $this->cache->set($cacheKey, $data, self::DEFAULT_TTL);

        $this->logger->debug('Cached counter', [
            'name' => $name,
            'window_start' => $windowStart,
        ]);
    }

    public function invalidateCounter(string $name, int $windowStart): void
    {
        $cacheKey = $this->buildCounterCacheKey($name, $windowStart);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated counter cache', [
            'name' => $name,
            'window_start' => $windowStart,
        ]);
    }

    public function refreshCounter(string $name, int $windowStart): void
    {
        $counter = $this->metricsRepository->getCounter($name, $windowStart);

        if ($counter === null) {
            $this->cache->delete($this->buildCounterCacheKey($name, $windowStart));
            return;
        }

        $data = $this->serializeCounter($counter);
        $this->setCounter($name, $windowStart, $data);

        $this->logger->debug('Refreshed counter cache', [
            'name' => $name,
            'window_start' => $windowStart,
        ]);
    }

    public function warmCounters(string $name, array $windows): void
    {
        foreach ($windows as $windowStart) {
            $counter = $this->metricsRepository->getCounter($name, $windowStart);

            if ($counter !== null) {
                $data = $this->serializeCounter($counter);
                $this->setCounter($name, $windowStart, $data);
            }
        }

        $this->logger->debug('Warmed counter cache', [
            'name' => $name,
            'windows_warmed' => count($windows),
        ]);
    }

    public function getGauge(string $name, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildGaugeCacheKey($name);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metricsService->increment('cache.hit', ['type' => 'metrics_gauge']);
            return $cached;
        }

        $this->metricsService->increment('cache.miss', ['type' => 'metrics_gauge']);

        $gauge = $this->metricsRepository->getGauge($name);

        if ($gauge === null) {
            return null;
        }

        $data = $this->serializeGauge($gauge);
        $this->setGauge($name, $data);

        return $data;
    }

    public function setGauge(string $name, array $data): void
    {
        $cacheKey = $this->buildGaugeCacheKey($name);

        $this->cache->set($cacheKey, $data, self::DEFAULT_TTL);

        $this->logger->debug('Cached gauge', [
            'name' => $name,
        ]);
    }

    public function invalidateGauge(string $name): void
    {
        $cacheKey = $this->buildGaugeCacheKey($name);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated gauge cache', [
            'name' => $name,
        ]);
    }

    public function refreshGauge(string $name): void
    {
        $gauge = $this->metricsRepository->getGauge($name);

        if ($gauge === null) {
            $this->cache->delete($this->buildGaugeCacheKey($name));
            return;
        }

        $data = $this->serializeGauge($gauge);
        $this->setGauge($name, $data);

        $this->logger->debug('Refreshed gauge cache', [
            'name' => $name,
        ]);
    }

    public function warmGauges(array $names): void
    {
        foreach ($names as $name) {
            $gauge = $this->metricsRepository->getGauge($name);

            if ($gauge !== null) {
                $data = $this->serializeGauge($gauge);
                $this->setGauge($name, $data);
            }
        }

        $this->logger->debug('Warmed gauges cache', [
            'gauges_warmed' => count($names),
        ]);
    }

    public function getHistogram(string $name, string $percentile, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildHistogramCacheKey($name, $percentile);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metricsService->increment('cache.hit', ['type' => 'metrics_histogram']);
            return $cached;
        }

        $this->metricsService->increment('cache.miss', ['type' => 'metrics_histogram']);

        $histogram = $this->metricsRepository->getHistogram($name, $percentile);

        if ($histogram === null) {
            return null;
        }

        $data = $this->serializeHistogram($histogram);
        $this->setHistogram($name, $percentile, $data);

        return $data;
    }

    public function setHistogram(string $name, string $percentile, array $data): void
    {
        $cacheKey = $this->buildHistogramCacheKey($name, $percentile);

        $this->cache->set($cacheKey, $data, self::AGGREGATE_TTL);

        $this->logger->debug('Cached histogram', [
            'name' => $name,
            'percentile' => $percentile,
        ]);
    }

    public function invalidateHistogram(string $name, string $percentile): void
    {
        $cacheKey = $this->buildHistogramCacheKey($name, $percentile);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated histogram cache', [
            'name' => $name,
            'percentile' => $percentile,
        ]);
    }

    public function refreshHistogram(string $name, string $percentile): void
    {
        $histogram = $this->metricsRepository->getHistogram($name, $percentile);

        if ($histogram === null) {
            $this->cache->delete($this->buildHistogramCacheKey($name, $percentile));
            return;
        }

        $data = $this->serializeHistogram($histogram);
        $this->setHistogram($name, $percentile, $data);

        $this->logger->debug('Refreshed histogram cache', [
            'name' => $name,
            'percentile' => $percentile,
        ]);
    }

    public function warmHistograms(string $name, array $percentiles): void
    {
        foreach ($percentiles as $percentile) {
            $histogram = $this->metricsRepository->getHistogram($name, $percentile);

            if ($histogram !== null) {
                $data = $this->serializeHistogram($histogram);
                $this->setHistogram($name, $percentile, $data);
            }
        }

        $this->logger->debug('Warmed histogram cache', [
            'name' => $name,
            'percentiles_warmed' => count($percentiles),
        ]);
    }

    public function getAggregation(string $name, string $interval, int $windowStart, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildAggregationCacheKey($name, $interval, $windowStart);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metricsService->increment('cache.hit', ['type' => 'metrics_aggregation']);
            return $cached;
        }

        $this->metricsService->increment('cache.miss', ['type' => 'metrics_aggregation']);

        $aggregation = $this->metricsRepository->getAggregation($name, $interval, $windowStart);

        if ($aggregation === null) {
            return null;
        }

        $data = $this->serializeAggregation($aggregation);
        $this->setAggregation($name, $interval, $windowStart, $data);

        return $data;
    }

    public function setAggregation(string $name, string $interval, int $windowStart, array $data): void
    {
        $cacheKey = $this->buildAggregationCacheKey($name, $interval, $windowStart);

        $this->cache->set($cacheKey, $data, self::AGGREGATE_TTL);

        $this->logger->debug('Cached aggregation', [
            'name' => $name,
            'interval' => $interval,
            'window_start' => $windowStart,
        ]);
    }

    public function invalidateAggregation(string $name, string $interval, int $windowStart): void
    {
        $cacheKey = $this->buildAggregationCacheKey($name, $interval, $windowStart);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated aggregation cache', [
            'name' => $name,
            'interval' => $interval,
            'window_start' => $windowStart,
        ]);
    }

    public function refreshAggregation(string $name, string $interval, int $windowStart): void
    {
        $aggregation = $this->metricsRepository->getAggregation($name, $interval, $windowStart);

        if ($aggregation === null) {
            $this->cache->delete($this->buildAggregationCacheKey($name, $interval, $windowStart));
            return;
        }

        $data = $this->serializeAggregation($aggregation);
        $this->setAggregation($name, $interval, $windowStart, $data);

        $this->logger->debug('Refreshed aggregation cache', [
            'name' => $name,
            'interval' => $interval,
            'window_start' => $windowStart,
        ]);
    }

    public function handleMetricsUpdate(string $name): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'counter', $name, '*');
        $this->cache->deleteByPattern($pattern);

        $gaugeKey = $this->buildGaugeCacheKey($name);
        $this->cache->delete($gaugeKey);

        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'histogram', $name, '*');
        $this->cache->deleteByPattern($pattern);

        $this->metricsService->increment('cache.invalidation', [
            'type' => 'metrics_update',
            'name' => $name,
        ]);

        $this->logger->info('Handled metrics update cache invalidation', [
            'name' => $name,
        ]);
    }

    public function handleRetentionPolicyChange(): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, '*');
        $this->cache->deleteByPattern($pattern);

        $this->metricsService->increment('cache.invalidation', [
            'type' => 'retention_policy_change',
        ]);

        $this->logger->info('Handled retention policy change cache invalidation');
    }

    private function buildCounterCacheKey(string $name, int $windowStart): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'counter', $name, (string) $windowStart);
    }

    private function buildGaugeCacheKey(string $name): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'gauge', $name);
    }

    private function buildHistogramCacheKey(string $name, string $percentile): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'histogram', $name, $percentile);
    }

    private function buildAggregationCacheKey(string $name, string $interval, int $windowStart): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'aggregation', $name, $interval, (string) $windowStart);
    }

    private function serializeCounter(object $counter): array
    {
        return [
            'name' => $counter->getName(),
            'value' => $counter->getValue(),
            'window_start' => $counter->getWindowStart(),
            'window_end' => $counter->getWindowEnd(),
        ];
    }

    private function serializeGauge(object $gauge): array
    {
        return [
            'name' => $gauge->getName(),
            'value' => $gauge->getValue(),
            'timestamp' => $gauge->getTimestamp()?->format(\DATE_ATOM),
        ];
    }

    private function serializeHistogram(object $histogram): array
    {
        return [
            'name' => $histogram->getName(),
            'percentile' => $histogram->getPercentile(),
            'value' => $histogram->getValue(),
            'count' => $histogram->getCount(),
        ];
    }

    private function serializeAggregation(object $aggregation): array
    {
        return [
            'name' => $aggregation->getName(),
            'interval' => $aggregation->getInterval(),
            'window_start' => $aggregation->getWindowStart(),
            'sum' => $aggregation->getSum(),
            'count' => $aggregation->getCount(),
            'min' => $aggregation->getMin(),
            'max' => $aggregation->getMax(),
            'avg' => $aggregation->getAvg(),
        ];
    }
}
