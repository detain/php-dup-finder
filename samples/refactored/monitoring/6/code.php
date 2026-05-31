<?php

declare(strict_types=1);

namespace App\Monitoring\Core;

interface RequestMetricsCollectorInterface
{
    public function recordStarted(RequestContext $context): void;
    public function recordCompleted(RequestContext $context, ResponseContext $response): void;
    public function recordError(RequestContext $context, \Exception $error): void;
}

abstract class AbstractRequestMetricsCollector implements RequestMetricsCollectorInterface
{
    protected MetricsBackend $backend;
    protected LoggerInterface $logger;

    protected function buildLabels(RequestContext $context): array
    {
        return [
            'method' => $context->getMethod(),
            'path' => $context->getNormalizedPath(),
            'service' => $context->getService()
        ];
    }

    public function recordStarted(RequestContext $context): void
    {
        $labels = $this->buildLabels($context);

        $this->backend->incrementCounter(
            $this->getPrefix() . '_requests_started_total',
            1,
            $labels
        );

        $this->backend->gauge(
            $this->getPrefix() . '_requests_in_progress',
            1,
            $labels
        );
    }

    public function recordCompleted(RequestContext $context, ResponseContext $response): void
    {
        $labels = array_merge($this->buildLabels($context), [
            'status_code' => (string)$response->getStatusCode()
        ]);

        $this->backend->incrementCounter(
            $this->getPrefix() . '_requests_completed_total',
            1,
            $labels
        );

        $this->backend->histogram(
            $this->getPrefix() . '_request_duration_ms',
            $response->getDurationMs(),
            $labels
        );

        $this->backend->gauge(
            $this->getPrefix() . '_requests_in_progress',
            -1,
            $this->buildLabels($context)
        );
    }

    abstract protected function getPrefix(): string;
}

class HttpMetricsCollector extends AbstractRequestMetricsCollector
{
    protected function getPrefix(): string
    {
        return 'http';
    }
}

class GrpcMetricsCollector extends AbstractRequestMetricsCollector
{
    protected function getPrefix(): string
    {
        return 'grpc';
    }
}

class QueueMetricsCollector extends AbstractRequestMetricsCollector
{
    protected function getPrefix(): string
    {
        return 'queue';
    }
}

class UnifiedRequestMetrics
{
    private array $collectors = [];

    public function registerCollector(string $type, RequestMetricsCollectorInterface $collector): void
    {
        $this->collectors[$type] = $collector;
    }

    public function recordStarted(string $type, RequestContext $context): void
    {
        $this->collectors[$type]?->recordStarted($context);
    }

    public function recordCompleted(string $type, RequestContext $context, ResponseContext $response): void
    {
        $this->collectors[$type]?->recordCompleted($context, $response);
    }

    public function recordError(string $type, RequestContext $context, \Exception $error): void
    {
        $this->collectors[$type]?->recordError($context, $error);
    }
}
