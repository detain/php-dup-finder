<?php

declare(strict_types=1);

namespace App\Cache;

class SessionCacheService
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

    public function get(string $sessionId): ?array
    {
        $cacheKey = "session:{$sessionId}";
        $operationId = uniqid('session_cache_', true);
        $startTime = microtime(true);

        $this->logCacheOperation('session', 'get', $operationId, $cacheKey);

        try {
            $value = $this->cache->get($cacheKey);
            $hit = $value !== null;

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordCacheHit(
                'session',
                $hit,
                $duration,
                $cacheKey
            );

            return $value;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logCacheError('session', 'get', $operationId, $e, $duration);
            $this->recordCacheError('session', $e, $duration, $cacheKey);

            return null;
        }
    }

    public function set(string $sessionId, array $data, int $ttl = 7200): bool
    {
        $cacheKey = "session:{$sessionId}";
        $operationId = uniqid('session_cache_', true);
        $startTime = microtime(true);

        $this->logCacheOperation('session', 'set', $operationId, $cacheKey);

        try {
            $result = $this->cache->set($cacheKey, $data, $ttl);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordCacheSet(
                'session',
                $result,
                $duration,
                $cacheKey,
                $ttl
            );

            return $result;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logCacheError('session', 'set', $operationId, $e, $duration);
            $this->recordCacheError('session', $e, $duration, $cacheKey);

            return false;
        }
    }

    public function delete(string $sessionId): bool
    {
        $cacheKey = "session:{$sessionId}";
        $operationId = uniqid('session_cache_', true);
        $startTime = microtime(true);

        $this->logCacheOperation('session', 'delete', $operationId, $cacheKey);

        try {
            $result = $this->cache->delete($cacheKey);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordCacheDelete(
                'session',
                $result,
                $duration,
                $cacheKey
            );

            return $result;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logCacheError('session', 'delete', $operationId, $e, $duration);
            $this->recordCacheError('session', $e, $duration, $cacheKey);

            return false;
        }
    }

    public function refresh(string $sessionId, int $ttl = 7200): bool
    {
        $cacheKey = "session:{$sessionId}";
        $operationId = uniqid('session_cache_', true);
        $startTime = microtime(true);

        $this->logCacheOperation('session', 'refresh', $operationId, $cacheKey);

        try {
            $data = $this->cache->get($cacheKey);

            if ($data === null) {
                return false;
            }

            $result = $this->cache->set($cacheKey, $data, $ttl);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordCacheRefresh(
                'session',
                $result,
                $duration,
                $cacheKey,
                $ttl
            );

            return $result;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logCacheError('session', 'refresh', $operationId, $e, $duration);
            $this->recordCacheError('session', $e, $duration, $cacheKey);

            return false;
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

    private function recordCacheRefresh(
        string $namespace,
        bool $success,
        float $durationMs,
        string $cacheKey,
        int $ttl
    ): void {
        $labels = [
            'namespace' => $namespace,
            'operation' => 'refresh',
            'success' => $success ? 'true' : 'false',
            'cache_key' => $cacheKey
        ];

        $this->metrics->incrementCounter(
            'cache_refresh_operations_total',
            'Total cache refresh operations',
            1,
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
