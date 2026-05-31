<?php

declare(strict_types=1);

namespace App\Infrastructure\Configuration;

use App\Attributes\Configuration;

#[Configuration('health_check')]
final class HealthCheckConfig
{
    public function __construct(
        public readonly int $timeout = 5,
        public readonly int $retries = 3,
        public readonly int $retryDelay = 200,
        public readonly int $interval = 30,
        public readonly int $failureThreshold = 3,
        public readonly int $successThreshold = 2,
        public readonly bool $cacheResults = true,
        public readonly int $cacheTtl = 10,
    ) {}
}

#[Configuration('metrics')]
final class MetricsConfig
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly string $namespace = 'app',
        public readonly int $bufferSize = 1000,
        public readonly int $flushInterval = 60,
        public readonly bool $enableHistograms = true,
        public readonly bool $enableCounters = true,
        public readonly bool $enableGauges = true,
    ) {}
}

#[Configuration('logging')]
final class LoggingConfig
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public function __construct(
        public readonly string $level = 'info',
        public readonly string $path = '/var/log/app',
        public readonly int $maxFiles = 30,
        public readonly int $maxFileSize = 104857600,
        public readonly bool $rotationEnabled = true,
        public readonly bool $formatJson = true,
        public readonly int $bufferSize = 100,
        public readonly int $flushInterval = 60,
        public readonly bool $async = false,
    ) {}
}

abstract class AbstractMonitoredService
{
    protected abstract function getHealthCheckConfig(): HealthCheckConfig;
    protected abstract function getMetricsConfig(): MetricsConfig;

    protected function withHealthCheck(callable $operation): mixed
    {
        $config = $this->getHealthCheckConfig();

        for ($attempt = 0; $attempt < $config->retries; $attempt++) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                if ($attempt >= $config->retries - 1) {
                    throw $e;
                }
                usleep($config->retryDelay * 1000 * ($attempt + 1));
            }
        }
    }
}
