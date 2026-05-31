<?php

declare(strict_types=1);

namespace App\Cache;

trait CacheMonitoringTrait
{
    private MetricsCollector $metrics;
    private LoggerInterface $logger;

    protected function monitorCacheOperation(
        string $namespace,
        string $operation,
        callable $action,
        string $cacheKey,
        int $ttl = 0
    ): mixed {
        $operationId = uniqid("{$namespace}_cache_", true);
        $startTime = microtime(true);

        $this->logger->debug('Cache operation started', [
            'namespace' => $namespace,
            'operation' => $operation,
            'operation_id' => $operationId,
            'cache_key' => $cacheKey
        ]);

        try {
            $result = $action();
            $duration = (microtime(true) - $startTime) * 1000;

            $success = $this->isCacheSuccess($result, $operation);

            $this->logger->info('Cache operation completed', [
                'namespace' => $namespace,
                'operation' => $operation,
                'operation_id' => $operationId,
                'success' => $success,
                'duration_ms' => round($duration, 2)
            ]);

            $this->recordCacheMetrics($namespace, $operation, $success, $duration, $cacheKey, $ttl);

            return $result;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logger->error('Cache operation failed', [
                'namespace' => $namespace,
                'operation' => $operation,
                'operation_id' => $operationId,
                'error' => $e->getMessage(),
                'duration_ms' => round($duration, 2)
            ]);

            $this->recordCacheError($namespace, $e, $duration, $cacheKey);

            throw $e;
        }
    }

    private function recordCacheMetrics(
        string $namespace,
        string $operation,
        bool $success,
        float $durationMs,
        string $cacheKey,
        int $ttl
    ): void {
        $labels = [
            'namespace' => $namespace,
            'operation' => $operation,
            'success' => $success ? 'true' : 'false',
            'cache_key' => $cacheKey
        ];

        $this->metrics->incrementCounter('cache_operations_total', 1, $labels);
        $this->metrics->histogram('cache_operation_duration_ms', $durationMs, [
            'namespace' => $namespace,
            'operation' => $operation
        ]);

        if ($ttl > 0) {
            $this->metrics->gauge('cache_ttl_seconds', $ttl, ['namespace' => $namespace]);
        }
    }

    private function recordCacheError(
        string $namespace,
        \Exception $e,
        float $durationMs,
        string $cacheKey
    ): void {
        $this->metrics->incrementCounter('cache_errors_total', 1, [
            'namespace' => $namespace,
            'error_type' => get_class($e),
            'cache_key' => $cacheKey
        ]);
    }

    private function isCacheSuccess(mixed $result, string $operation): bool
    {
        if (is_bool($result)) {
            return $result;
        }

        return $result !== null;
    }
}

abstract class AbstractCacheService
{
    use CacheMonitoringTrait;

    protected CacheInterface $cache;

    abstract protected function getNamespace(): string;

    protected function cacheGet(string $key, callable $fallback, int $ttl = 3600): mixed
    {
        $cacheKey = "{$this->getNamespace()}:{$key}";

        return $this->monitorCacheOperation(
            $this->getNamespace(),
            'get',
            function () use ($cacheKey, $fallback, $ttl) {
                $value = $this->cache->get($cacheKey);

                if ($value !== null) {
                    return $value;
                }

                $value = $fallback();

                if ($value !== null) {
                    $this->cache->set($cacheKey, $value, $ttl);
                }

                return $value;
            },
            $cacheKey,
            $ttl
        );
    }

    protected function cacheSet(string $key, mixed $value, int $ttl = 3600): bool
    {
        $cacheKey = "{$this->getNamespace()}:{$key}";

        return $this->monitorCacheOperation(
            $this->getNamespace(),
            'set',
            fn() => $this->cache->set($cacheKey, $value, $ttl),
            $cacheKey,
            $ttl
        );
    }

    protected function cacheDelete(string $key): bool
    {
        $cacheKey = "{$this->getNamespace()}:{$key}";

        return $this->monitorCacheOperation(
            $this->getNamespace(),
            'delete',
            fn() => $this->cache->delete($cacheKey),
            $cacheKey
        );
    }
}
