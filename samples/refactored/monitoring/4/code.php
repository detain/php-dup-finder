<?php

declare(strict_types=1);

namespace App\Monitoring\Core;

interface HealthCheckInterface
{
    public function getName(): string;
    public function getType(): string;
    public function execute(): HealthCheckResult;
}

interface HealthAggregatorInterface
{
    public function getLivenessStatus(): HealthStatus;
    public function getReadinessStatus(): HealthStatus;
    public function registerCheck(HealthCheckInterface $check): void;
}

abstract class AbstractHealthCheck implements HealthCheckInterface
{
    protected string $name;
    protected LoggerInterface $logger;

    public function getType(): string
    {
        return 'basic';
    }

    public function execute(): HealthCheckResult
    {
        $start = microtime(true);

        try {
            $result = $this->performCheck();

            return new HealthCheckResult(
                passed: $result->isPassed(),
                message: $result->getMessage(),
                durationMs: (microtime(true) - $start) * 1000,
                metadata: $result->getMetadata()
            );
        } catch (\Exception $e) {
            $this->logger->error("Health check {$this->name} failed", ['error' => $e->getMessage()]);

            return new HealthCheckResult(
                passed: false,
                message: $e->getMessage(),
                durationMs: (microtime(true) - $start) * 1000
            );
        }
    }

    abstract protected function performCheck(): HealthCheckResult;
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

    protected function performCheck(): HealthCheckResult
    {
        $this->pdo->query('SELECT 1');

        return new HealthCheckResult(passed: true, message: 'Database is alive');
    }
}

class UnifiedHealthAggregator implements HealthAggregatorInterface
{
    private array $checks = [];

    public function registerCheck(HealthCheckInterface $check): void
    {
        $this->checks[$check->getName()] = $check;
    }

    public function getLivenessStatus(): HealthStatus
    {
        $results = [];

        foreach ($this->checks as $check) {
            if ($check->getType() === 'liveness') {
                $results[] = $check->execute();
            }
        }

        return new HealthStatus($results);
    }

    public function getReadinessStatus(): HealthStatus
    {
        $results = [];

        foreach ($this->checks as $check) {
            if ($check->getType() === 'readiness') {
                $results[] = $check->execute();
            }
        }

        return new HealthStatus($results);
    }
}
