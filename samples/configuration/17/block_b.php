<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use Psr\Log\LoggerInterface;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Prometheus\RenderTextFormat;

final class MetricsCollectorService
{
    private const METRICS_ENABLED = true;
    private const METRICS_NAMESPACE = 'app';
    private const METRICS_STORAGE = 'memory';
    private const METRICS_TIMEOUT = 5;
    private const METRICS_BUFFER_SIZE = 1000;
    private const METRICS_FLUSH_INTERVAL = 60;
    private const METRICS_ENABLE_HISTOGRAMS = true;
    private const METRICS_ENABLE_COUNTERS = true;
    private const METRICS_ENABLE_GAUGES = true;
    private const METRICS_INCLUDE_PROCESS_STATS = true;
    private const METRICS_INCLUDE_MEMORY_STATS = true;

    private static array $defaultLabels = ['service' => 'phpdup'];

    private CollectorRegistry $registry;
    private array $buffer = [];
    private ?int $lastFlush = null;
    private LoggerInterface $logger;

    public function __construct(
        LoggerInterface $logger,
        ?CollectorRegistry $registry = null
    ) {
        $this->logger = $logger;

        if ($registry !== null) {
            $this->registry = $registry;
        } else {
            $this->registry = new CollectorRegistry(new InMemory());
        }

        $this->lastFlush = time();
    }

    public function incrementCounter(string $name, array $labels = [], int $value = 1): void
    {
        if (!self::METRICS_ENABLED || !self::METRICS_ENABLE_COUNTERS) {
            return;
        }

        try {
            $counter = $this->registry->getOrRegisterCounter(
                self::METRICS_NAMESPACE,
                $name,
                $name,
                array_keys($labels)
            );

            $counter->incBy($value, array_values($labels));

            $this->bufferMetric('counter', $name, $labels, $value);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to increment counter', [
                'name' => $name,
                'labels' => $labels,
                'error' => $e->getMessage(),
                'timeout' => self::METRICS_TIMEOUT,
            ]);
        }
    }

    public function setGauge(string $name, float $value, array $labels = []): void
    {
        if (!self::METRICS_ENABLED || !self::METRICS_ENABLE_GAUGES) {
            return;
        }

        try {
            $gauge = $this->registry->getOrRegisterGauge(
                self::METRICS_NAMESPACE,
                $name,
                $name,
                array_keys($labels)
            );

            $gauge->set($value, array_values($labels));

            $this->bufferMetric('gauge', $name, $labels, $value);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to set gauge', [
                'name' => $name,
                'value' => $value,
                'labels' => $labels,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function observeHistogram(string $name, float $value, array $labels = []): void
    {
        if (!self::METRICS_ENABLED || !self::METRICS_ENABLE_HISTOGRAMS) {
            return;
        }

        try {
            $histogram = $this->registry->getOrRegisterHistogram(
                self::METRICS_NAMESPACE,
                $name,
                $name,
                array_keys($labels),
                [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]
            );

            $histogram->observe($value, array_values($labels));

            $this->bufferMetric('histogram', $name, $labels, $value);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to observe histogram', [
                'name' => $name,
                'value' => $value,
                'labels' => $labels,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function recordTiming(string $name, float $durationMs, array $labels = []): void
    {
        $labels['unit'] = 'milliseconds';
        $this->observeHistogram($name . '_duration', $durationMs, $labels);
    }

    public function recordCount(string $name, int $count, array $labels = []): void
    {
        $this->incrementCounter($name . '_total', $labels, $count);
    }

    private function bufferMetric(string $type, string $name, array $labels, float $value): void
    {
        if (count($this->buffer) >= self::METRICS_BUFFER_SIZE) {
            $this->flush();
        }

        $this->buffer[] = [
            'type' => $type,
            'name' => $name,
            'labels' => $labels,
            'value' => $value,
            'timestamp' => microtime(true),
        ];
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $bufferCount = count($this->buffer);
        $this->buffer = [];
        $this->lastFlush = time();

        $this->logger->debug('Metrics buffer flushed', [
            'count' => $bufferCount,
            'buffer_size' => self::METRICS_BUFFER_SIZE,
            'flush_interval' => self::METRICS_FLUSH_INTERVAL,
        ]);
    }

    public function shouldFlush(): bool
    {
        if (empty($this->buffer)) {
            return false;
        }

        return (time() - $this->lastFlush) >= self::METRICS_FLUSH_INTERVAL;
    }

    public function render(): string
    {
        $renderer = new RenderTextFormat();
        return $renderer->render($this->registry->getMetricFamilySamples());
    }

    public function getRegistry(): CollectorRegistry
    {
        return $this->registry;
    }

    public function getDefaultLabels(): array
    {
        return self::$defaultLabels;
    }

    public function addDefaultLabel(string $name, string $value): void
    {
        self::$defaultLabels[$name] = $value;
    }
}
