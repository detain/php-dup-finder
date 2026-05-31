<?php

declare(strict_types=1);

namespace App\Cache;

class ConfigCacheService
{
    private CacheInterface $cache;
    private MetricsCollector $metrics;
    private LoggerInterface $logger;

    public function __construct(
        CacheInterface $cache,
        MetricsCollector $metrics,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function get(string $configKey, callable $fallback, int $ttl = 3600): mixed
    {
        $cacheKey = "config:{$configKey}";
        $operationId = uniqid('config_cache_', true);
        $startTime = microtime(true);

        $this->logCacheOperation('config', 'get', $operationId, $cacheKey);

        try {
            $value = $this->cache->get($cacheKey);
            $hit = $value !== null;

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordCacheHit(
                'config',
                $hit,
                $duration,
                $cacheKey
            );

            if ($hit) {
                return $value;
            }

            $value = $fallback();

            if ($value !== null) {
                $this->cache->set($cacheKey, $value, $ttl);
                $this->recordCacheMiss(
                    'config',
                    $cacheKey,
                    $ttl
                );
            }

            return $value;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logCacheError('config', 'get', $operationId, $e, $duration);
            $this->recordCacheError('config', $e, $duration, $cacheKey);

            return $fallback();
        }
    }

    public function set(string $configKey, mixed $value, int $ttl = 3600): bool
    {
        $cacheKey = "config:{$configKey}";
        $operationId = uniqid('config_cache_', true);
        $startTime = microtime(true);

        $this->logCacheOperation('config', 'set', $operationId, $cacheKey);

        try {
            $result = $this->cache->set($cacheKey, $value, $ttl);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordCacheSet(
                'config',
                $result,
                $duration,
                $cacheKey,
                $ttl
            );

            return $result;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logCacheError('config', 'set', $operationId, $e, $duration);
            $this->recordCacheError('config', $e, $duration, $cacheKey);

            return false;
        }
    }

    public function invalidate(string $configKey): bool
    {
        $cacheKey = "config:{$configKey}";
        $operationId = uniqid('config_cache_', true);
        $startTime = microtime(true);

        $this->logCacheOperation('config', 'invalidate', $operationId, $cacheKey);

        try {
            $result = $this->cache->delete($cacheKey);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordCacheInvalidate(
                'config',
                $result ? 1 : 0,
                $duration,
                $cacheKey
            );

            return $result;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logCacheError('config', 'invalidate', $operationId, $e, $duration);
            $this->recordCacheError('config', $e, $duration, $cacheKey);

            return false;
        }
    }

    public function invalidateAll(): int
    {
        $operationId = uniqid('config_cache_', true);
        $startTime = microtime(true);

        $this->logCacheOperation('config', 'invalidate_all', $operationId, 'config:*');

        try {
            $count = $this->cache->invalidate('config:*');

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordCacheBulkInvalidate(
                'config',
                $count,
                $duration
            );

            return $count;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logCacheError('config', 'invalidate_all', $operationId, $e, $duration);
            $this->recordCacheError('config', $e, $duration, 'config:*');

            return 0;
        }
    }

    private function logCacheOperation(
        string $namespace,
        string $operation,
        string $operationId,
        string $cacheKey
    ): void {
        $this->logger->debug('Cache operation started', [
            'namespace' => $namespace,
            'operation' => $operation,
            'operation_id' => $operationId,
            'cache_key' => $cacheKey
        ]);
    }

    private function recordCacheHit(
        string $namespace,
        bool $hit,
        float $durationMs,
        string $cacheKey
    ): void {
        $labels = [
            'namespace' => $namespace,
            'operation' => 'get',
            'hit' => $hit ? 'true' : 'false',
            'cache_key' => $cacheKey
        ];

        $this->metrics->incrementCounter(
            'cache_operations_total',
            'Total cache operations',
            1,
            $labels
        );

        $this->metrics->histogram(
            'cache_operation_duration_ms',
            'Cache operation duration in milliseconds',
            $durationMs,
            ['namespace' => $namespace, 'operation' => 'get']
        );
    }

    private function recordCacheMiss(
        string $namespace,
        string $cacheKey,
        int $ttl
    ): void {
        $labels = [
            'namespace' => $namespace,
            'cache_key' => $cacheKey
        ];

        $this->metrics->incrementCounter(
            'cache_misses_total',
            'Total cache misses',
            1,
            $labels
        );

        $this->metrics->gauge(
            'cache_ttl_seconds',
            'Cache TTL in seconds',
            $ttl,
            $labels
        );
    }

    private function recordCacheSet(
        string $namespace,
        bool $success,
        float $durationMs,
        string $cacheKey,
        int $ttl
    ): void {
        $labels = [
            'namespace' => $namespace,
            'operation' => 'set',
            'success' => $success ? 'true' : 'false',
            'cache_key' => $cacheKey
        ];

        $this->metrics->incrementCounter(
            'cache_operations_total',
            'Total cache operations',
            1,
            $labels
        );

        $this->metrics->histogram(
            'cache_operation_duration_ms',
            'Cache operation duration in milliseconds',
            $durationMs,
            ['namespace' => $namespace, 'operation' => 'set']
        );
    }

    private function recordCacheInvalidate(
        string $namespace,
        int $count,
        float $durationMs,
        string $cacheKey
    ): void {
        $labels = [
            'namespace' => $namespace,
            'operation' => 'invalidate'
        ];

        $this->metrics->incrementCounter(
            'cache_invalidations_total',
            'Total cache invalidations',
            $count,
            $labels
        );
    }

    private function recordCacheBulkInvalidate(
        string $namespace,
        int $count,
        float $durationMs
    ): void {
        $labels = [
            'namespace' => $namespace,
            'operation' => 'bulk_invalidate'
        ];

        $this->metrics->incrementCounter(
            'cache_bulk_invalidations_total',
            'Total bulk cache invalidations',
            1,
            $labels
        );

        $this->metrics->histogram(
            'cache_operation_duration_ms',
            'Cache operation duration in milliseconds',
            $durationMs,
            $labels
        );
    }

    private function logCacheError(
        string $namespace,
        string $operation,
        string $operationId,
        \Exception $e,
        float $durationMs
    ): void {
        $this->logger->error('Cache operation failed', [
            'namespace' => $namespace,
            'operation' => $operation,
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'error_type' => get_class($e),
            'duration_ms' => round($durationMs, 2)
        ]);
    }

    private function recordCacheError(
        string $namespace,
        \Exception $e,
        float $durationMs,
        string $cacheKey
    ): void {
        $labels = [
            'namespace' => $namespace,
            'error_type' => get_class($e),
            'cache_key' => $cacheKey
        ];

        $this->metrics->incrementCounter(
            'cache_errors_total',
            'Total cache errors',
            1,
            $labels
        );
    }
}
