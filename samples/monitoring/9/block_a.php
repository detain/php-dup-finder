<?php

declare(strict_types=1);

namespace App\Cache;

class UserCacheService
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

    public function get(string $key, callable $fallback, int $ttl = 3600): mixed
    {
        $cacheKey = "user:{$key}";
        $operationId = uniqid('user_cache_', true);
        $startTime = microtime(true);

        $this->logCacheOperation('user', 'get', $operationId, $cacheKey);

        try {
            $value = $this->cache->get($cacheKey);
            $hit = $value !== null;

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordCacheHit(
                'user',
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
                    'user',
                    $cacheKey,
                    $ttl
                );
            }

            return $value;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logCacheError('user', 'get', $operationId, $e, $duration);
            $this->recordCacheError('user', $e, $duration, $cacheKey);

            return $fallback();
        }
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $cacheKey = "user:{$key}";
        $operationId = uniqid('user_cache_', true);
        $startTime = microtime(true);

        $this->logCacheOperation('user', 'set', $operationId, $cacheKey);

        try {
            $result = $this->cache->set($cacheKey, $value, $ttl);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordCacheSet(
                'user',
                $result,
                $duration,
                $cacheKey,
                $ttl
            );

            return $result;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logCacheError('user', 'set', $operationId, $e, $duration);
            $this->recordCacheError('user', $e, $duration, $cacheKey);

            return false;
        }
    }

    public function delete(string $key): bool
    {
        $cacheKey = "user:{$key}";
        $operationId = uniqid('user_cache_', true);
        $startTime = microtime(true);

        $this->logCacheOperation('user', 'delete', $operationId, $cacheKey);

        try {
            $result = $this->cache->delete($cacheKey);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordCacheDelete(
                'user',
                $result,
                $duration,
                $cacheKey
            );

            return $result;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logCacheError('user', 'delete', $operationId, $e, $duration);
            $this->recordCacheError('user', $e, $duration, $cacheKey);

            return false;
        }
    }

    public function invalidate(string $pattern): int
    {
        $cachePattern = "user:{$pattern}";
        $operationId = uniqid('user_cache_', true);
        $startTime = microtime(true);

        $this->logCacheOperation('user', 'invalidate', $operationId, $cachePattern);

        try {
            $count = $this->cache->invalidate($cachePattern);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordCacheInvalidate(
                'user',
                $count,
                $duration,
                $cachePattern
            );

            return $count;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logCacheError('user', 'invalidate', $operationId, $e, $duration);
            $this->recordCacheError('user', $e, $duration, $cachePattern);

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

    private function recordCacheDelete(
        string $namespace,
        bool $success,
        float $durationMs,
        string $cacheKey
    ): void {
        $labels = [
            'namespace' => $namespace,
            'operation' => 'delete',
            'success' => $success ? 'true' : 'false',
            'cache_key' => $cacheKey
        ];

        $this->metrics->incrementCounter(
            'cache_operations_total',
            'Total cache operations',
            1,
            $labels
        );
    }

    private function recordCacheInvalidate(
        string $namespace,
        int $count,
        float $durationMs,
        string $pattern
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
