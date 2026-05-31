<?php

declare(strict_types=1);

namespace App\Monitoring\Kubernetes;

class K8sHealthProbeHandler
{
    private HealthRegistry $registry;
    private LoggerInterface $logger;

    public function __construct(HealthRegistry $registry, LoggerInterface $logger)
    {
        $this->registry = $registry;
        $this->logger = $logger;
    }

    public function handleLivenessProbe(): Response
    {
        $checks = $this->registry->getLivenessChecks();

        $allPassed = true;
        $details = [];

        foreach ($checks as $check) {
            $result = $check->execute();

            $details[] = [
                'name' => $check->getName(),
                'passed' => $result->isPassed(),
                'message' => $result->getMessage(),
                'duration_ms' => $result->getDurationMs()
            ];

            if (!$result->isPassed()) {
                $allPassed = false;
            }
        }

        $response = [
            'status' => $allPassed ? 'healthy' : 'unhealthy',
            'checks' => $details,
            'timestamp' => date('c')
        ];

        return new Response(
            json_encode($response),
            $allPassed ? 200 : 503
        );
    }

    public function handleReadinessProbe(): Response
    {
        $checks = $this->registry->getReadinessChecks();

        $allPassed = true;
        $details = [];

        foreach ($checks as $check) {
            $result = $check->execute();

            $details[] = [
                'name' => $check->getName(),
                'ready' => $result->isPassed(),
                'message' => $result->getMessage(),
                'duration_ms' => $result->getDurationMs()
            ];

            if (!$result->isPassed()) {
                $allPassed = false;
            }
        }

        $response = [
            'status' => $allPassed ? 'ready' : 'not_ready',
            'checks' => $details,
            'timestamp' => date('c')
        ];

        return new Response(
            json_encode($response),
            $allPassed ? 200 : 503
        );
    }

    public function handleStartupProbe(): Response
    {
        $checks = $this->registry->getStartupChecks();

        $allPassed = true;
        $details = [];

        foreach ($checks as $check) {
            $result = $check->execute();

            $details[] = [
                'name' => $check->getName(),
                'passed' => $result->isPassed(),
                'message' => $result->getMessage()
            ];

            if (!$result->isPassed()) {
                $allPassed = false;
            }
        }

        $response = [
            'status' => $allPassed ? 'started' : 'starting',
            'checks' => $details,
            'timestamp' => date('c')
        ];

        return new Response(
            json_encode($response),
            $allPassed ? 200 : 503
        );
    }
}

class HealthRegistry
{
    private array $livenessChecks = [];
    private array $readinessChecks = [];
    private array $startupChecks = [];

    public function registerLivenessCheck(HealthCheckInterface $check): void
    {
        $this->livenessChecks[] = $check;
    }

    public function registerReadinessCheck(HealthCheckInterface $check): void
    {
        $this->readinessChecks[] = $check;
    }

    public function registerStartupCheck(HealthCheckInterface $check): void
    {
        $this->startupChecks[] = $check;
    }

    public function getLivenessChecks(): array
    {
        return $this->livenessChecks;
    }

    public function getReadinessChecks(): array
    {
        return $this->readinessChecks;
    }

    public function getStartupChecks(): array
    {
        return $this->startupChecks;
    }
}

interface HealthCheckInterface
{
    public function getName(): string;
    public function execute(): HealthCheckResult;
}

abstract class AbstractHealthCheck implements HealthCheckInterface
{
    protected string $name;
    protected LoggerInterface $logger;

    public function getName(): string
    {
        return $this->name;
    }

    abstract public function doCheck(): HealthCheckResult;

    public function execute(): HealthCheckResult
    {
        $start = microtime(true);

        try {
            $result = $this->doCheck();

            return new HealthCheckResult(
                passed: $result->isPassed(),
                message: $result->getMessage(),
                durationMs: (microtime(true) - $start) * 1000
            );
        } catch (\Exception $e) {
            $this->logger->error("Health check {$this->name} failed", [
                'error' => $e->getMessage()
            ]);

            return new HealthCheckResult(
                passed: false,
                message: $e->getMessage(),
                durationMs: (microtime(true) - $start) * 1000
            );
        }
    }
}

class DatabaseLivenessCheck extends AbstractHealthCheck
{
    private PDO $pdo;

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->name = 'database_liveness';
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    public function doCheck(): HealthCheckResult
    {
        $this->pdo->query('SELECT 1');

        return new HealthCheckResult(passed: true, message: 'Database is alive');
    }
}

class DatabaseReadinessCheck extends AbstractHealthCheck
{
    private ConnectionPool $pool;

    public function __construct(ConnectionPool $pool, LoggerInterface $logger)
    {
        $this->name = 'database_readiness';
        $this->pool = $pool;
        $this->logger = $logger;
    }

    public function doCheck(): HealthCheckResult
    {
        $conn = $this->pool->getConnection();

        $this->pool->release($conn);

        if ($this->pool->getUsedConnections() >= $this->pool->getSize()) {
            return new HealthCheckResult(
                passed: false,
                message: 'Connection pool exhausted'
            );
        }

        return new HealthCheckResult(
            passed: true,
            message: 'Database is ready'
        );
    }
}

class CacheReadinessCheck extends AbstractHealthCheck
{
    private Redis $redis;

    public function __construct(Redis $redis, LoggerInterface $logger)
    {
        $this->name = 'cache_readiness';
        $this->redis = $redis;
        $this->logger = $logger;
    }

    public function doCheck(): HealthCheckResult
    {
        $pong = $this->redis->ping();

        if ($pong !== 'PONG' && $pong !== true) {
            return new HealthCheckResult(
                passed: false,
                message: 'Redis ping failed'
            );
        }

        $info = $this->redis->info('memory');
        $usedMemory = $info['used_memory'] ?? 0;
        $maxMemory = $this->redis->config('GET', 'maxmemory')['maxmemory'] ?? 0;

        if ($maxMemory > 0) {
            $utilization = ($usedMemory / $maxMemory) * 100;

            if ($utilization > 95) {
                return new HealthCheckResult(
                    passed: false,
                    message: "Redis memory critical: {$utilization}%"
                );
            }
        }

        return new HealthCheckResult(passed: true, message: 'Cache is ready');
    }
}

class QueueReadinessCheck extends AbstractHealthCheck
{
    private RabbitMQ $mq;

    public function __construct(RabbitMQ $mq, LoggerInterface $logger)
    {
        $this->name = 'queue_readiness';
        $this->mq = $mq;
        $this->logger = $logger;
    }

    public function doCheck(): HealthCheckResult
    {
        if (!$this->mq->isConnected()) {
            return new HealthCheckResult(
                passed: false,
                message: 'RabbitMQ not connected'
            );
        }

        $queues = $this->mq->getQueues();

        $criticalQueues = [];

        foreach ($queues as $queue) {
            if ($queue['messages'] > 10000) {
                $criticalQueues[] = $queue['name'];
            }
        }

        if (!empty($criticalQueues)) {
            return new HealthCheckResult(
                passed: false,
                message: 'Queue backlog critical: ' . implode(', ', $criticalQueues)
            );
        }

        return new HealthCheckResult(passed: true, message: 'Queue is ready');
    }
}

class ExternalServiceHealthCheck extends AbstractHealthCheck
{
    private array $services;

    public function __construct(array $services, LoggerInterface $logger)
    {
        $this->name = 'external_services';
        $this->services = $services;
        $this->logger = $logger;
    }

    public function doCheck(): HealthCheckResult
    {
        $unhealthyServices = [];

        foreach ($this->services as $service) {
            try {
                $health = $service->checkHealth();

                if (!$health['healthy']) {
                    $unhealthyServices[] = $service->getName();
                }
            } catch (\Exception $e) {
                $unhealthyServices[] = $service->getName();
            }
        }

        if (!empty($unhealthyServices)) {
            return new HealthCheckResult(
                passed: false,
                message: 'Unhealthy: ' . implode(', ', $unhealthyServices)
            );
        }

        return new HealthCheckResult(
            passed: true,
            message: 'All external services healthy'
        );
    }
}

class HealthCheckResult
{
    private bool $passed;
    private string $message;
    private float $durationMs;

    public function __construct(bool $passed, string $message, float $durationMs = 0)
    {
        $this->passed = $passed;
        $this->message = $message;
        $this->durationMs = $durationMs;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getDurationMs(): float
    {
        return $this->durationMs;
    }
}
