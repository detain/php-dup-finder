<?php

declare(strict_types=1);

namespace App\Monitoring\Core;

interface PerformanceCounterInterface
{
    public function record(string $metric, float $value, array $labels = []): void;
    public function increment(string $metric, int $delta = 1, array $labels = []): void;
    public function gauge(string $metric, int $value, array $labels = []): void;
}

abstract class AbstractPerformanceCounter implements PerformanceCounterInterface
{
    protected MetricsBackend $backend;
    protected AlertManager $alerts;
    protected LoggerInterface $logger;

    public function record(string $metric, float $value, array $labels = []): void
    {
        $this->backend->recordHistogram($metric, $value, $labels);
        $this->checkThresholds($metric, $value, $labels);
    }

    public function increment(string $metric, int $delta = 1, array $labels = []): void
    {
        $this->backend->incrementCounter($metric, $delta, $labels);
    }

    public function gauge(string $metric, int $value, array $labels = []): void
    {
        $this->backend->recordGauge($metric, $value, $labels);
    }

    protected function checkThresholds(string $metric, float $value, array $labels): void
    {
        $threshold = $this->getThreshold($metric);

        if ($threshold !== null && $value > $threshold) {
            $this->onThresholdExceeded($metric, $value, $threshold, $labels);
        }
    }

    abstract protected function getThreshold(string $metric): ?float;
    abstract protected function onThresholdExceeded(string $metric, float $value, float $threshold, array $labels): void;
}

class DatabasePerformanceCounter extends AbstractPerformanceCounter
{
    protected function getThreshold(string $metric): ?float
    {
        return match($metric) {
            'db.query.duration_seconds' => 0.1,
            'db.connection.wait_seconds' => 1.0,
            default => null
        };
    }

    protected function onThresholdExceeded(string $metric, float $value, float $threshold, array $labels): void
    {
        $this->alerts->trigger('slow_query', [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold,
            'labels' => $labels
        ]);
    }
}

class CachePerformanceCounter extends AbstractPerformanceCounter
{
    protected function getThreshold(string $metric): ?float
    {
        return match($metric) {
            'cache.operation_duration_seconds' => 0.01,
            default => null
        };
    }

    protected function onThresholdExceeded(string $metric, float $value, float $threshold, array $labels): void
    {
        $this->alerts->trigger('slow_cache_operation', [
            'metric' => $metric,
            'value' => $value,
            'labels' => $labels
        ]);
    }
}

class UnifiedPerformanceMonitoringService
{
    private array $counters = [];
    private ThresholdsConfig $thresholds;

    public function registerCounter(string $domain, PerformanceCounterInterface $counter): void
    {
        $this->counters[$domain] = $counter;
    }

    public function record(string $domain, string $metric, float $value, array $labels = []): void
    {
        if (!isset($this->counters[$domain])) {
            throw new \RuntimeException("No counter registered for domain: {$domain}");
        }

        $this->counters[$domain]->record($metric, $value, $labels);
    }

    public function increment(string $domain, string $metric, int $delta = 1, array $labels = []): void
    {
        if (!isset($this->counters[$domain])) {
            throw new \RuntimeException("No counter registered for domain: {$domain}");
        }

        $this->counters[$domain]->increment($metric, $delta, $labels);
    }

    public function gauge(string $domain, string $metric, int $value, array $labels = []): void
    {
        if (!isset($this->counters[$domain])) {
            throw new \RuntimeException("No counter registered for domain: {$domain}");
        }

        $this->counters[$domain]->gauge($metric, $value, $labels);
    }
}
