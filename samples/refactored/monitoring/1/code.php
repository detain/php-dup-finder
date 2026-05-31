<?php

declare(strict_types=1);

namespace App\Monitoring\Core;

interface MetricsCollectorInterface
{
    public function recordRequestStarted(RequestContext $context): void;
    public function recordRequestCompleted(RequestContext $context, ResponseContext $response): void;
    public function recordRequestError(RequestContext $context, ErrorContext $error): void;
}

interface MetricsRecorderInterface
{
    public function incrementCounter(string $name, string $description, int $value, array $labels = []): void;
    public function recordHistogram(string $name, string $description, float $value, array $labels = []): void;
    public function recordGauge(string $name, string $description, int $delta, array $labels = []): void;
}

abstract class AbstractMetricsCollector implements MetricsCollectorInterface
{
    protected MetricsRecorderInterface $recorder;
    protected AlertManager $alerts;

    public function recordRequestStarted(RequestContext $context): void
    {
        $labels = $context->toLabels();

        $this->recorder->incrementCounter(
            $this->getMetricPrefix() . '_requests_total',
            'Total requests',
            1,
            $labels
        );

        $this->recorder->recordGauge(
            $this->getMetricPrefix() . '_in_flight',
            'Requests in flight',
            1,
            $labels
        );

        $this->recorder->timing(
            $this->getMetricPrefix() . '_started_at',
            time(),
            $labels
        );
    }

    public function recordRequestCompleted(RequestContext $context, ResponseContext $response): void
    {
        $labels = array_merge($context->toLabels(), [
            'status_code' => (string)$response->getStatusCode()
        ]);

        $this->recorder->incrementCounter(
            $this->getMetricPrefix() . '_completed_total',
            'Completed requests',
            1,
            $labels
        );

        $this->recorder->recordHistogram(
            $this->getMetricPrefix() . '_duration_seconds',
            'Request duration',
            $response->getDurationMs() / 1000,
            $labels
        );

        $this->recorder->recordGauge(
            $this->getMetricPrefix() . '_in_flight',
            'Requests in flight',
            -1,
            $context->toLabels()
        );
    }

    abstract protected function getMetricPrefix(): string;
}

class ApiMetricsCollector extends AbstractMetricsCollector
{
    protected function getMetricPrefix(): string
    {
        return 'api';
    }
}

class WebhookMetricsCollector extends AbstractMetricsCollector
{
    protected function getMetricPrefix(): string
    {
        return 'webhook';
    }
}

class JobMetricsCollector extends AbstractMetricsCollector
{
    protected function getMetricPrefix(): string
    {
        return 'job';
    }
}

class UnifiedMetricsRecorder implements MetricsRecorderInterface
{
    private array $counters = [];
    private array $histograms = [];
    private array $gauges = [];

    public function incrementCounter(string $name, string $description, int $value, array $labels = []): void
    {
        $key = $this->makeKey($name, $labels);

        if (!isset($this->counters[$key])) {
            $this->counters[$key] = ['name' => $name, 'description' => $description, 'labels' => $labels, 'value' => 0];
        }

        $this->counters[$key]['value'] += $value;
    }

    public function recordHistogram(string $name, string $description, float $value, array $labels = []): void
    {
        $key = $this->makeKey($name, $labels);

        if (!isset($this->histograms[$key])) {
            $this->histograms[$key] = ['name' => $name, 'description' => $description, 'labels' => $labels, 'values' => []];
        }

        $this->histograms[$key]['values'][] = $value;
    }

    public function recordGauge(string $name, string $description, int $delta, array $labels = []): void
    {
        $key = $this->makeKey($name, $labels);

        if (!isset($this->gauges[$key])) {
            $this->gauges[$key] = ['name' => $name, 'description' => $description, 'labels' => $labels, 'value' => 0];
        }

        $this->gauges[$key]['value'] += $delta;
    }

    private function makeKey(string $name, array $labels): string
    {
        ksort($labels);

        return $name . ':' . json_encode($labels);
    }
}
