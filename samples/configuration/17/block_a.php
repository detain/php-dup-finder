<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Psr\Log\LoggerInterface;
use App\Models\HealthCheckResult;

final class HealthCheckService
{
    private const HEALTH_CHECK_TIMEOUT = 5;
    private const HEALTH_CHECK_RETRIES = 3;
    private const HEALTH_CHECK_RETRY_DELAY = 200;
    private const HEALTH_CHECK_INTERVAL = 30;
    private const HEALTH_CHECK_FAILURE_THRESHOLD = 3;
    private const HEALTH_CHECK_SUCCESS_THRESHOLD = 2;
    private const HEALTH_CHECK_ENABLE_DETAILED_RESULTS = true;
    private const HEALTH_CHECK_INCLUDE_DEPENDENCIES = true;
    private const HEALTH_CHECK_CACHE_RESULTS = true;
    private const HEALTH_CHECK_CACHE_TTL = 10;

    private array $checks = [];
    private array $healthHistory = [];
    private ?array $cachedResult = null;
    private ?int $cacheTimestamp = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly DatabaseHealthCheck $dbCheck,
        private readonly CacheHealthCheck $cacheCheck,
        private readonly ExternalApiHealthCheck $externalApiCheck
    ) {
        $this->registerDefaultChecks();
    }

    private function registerDefaultChecks(): void
    {
        $this->registerCheck('database', function () {
            return $this->dbCheck->execute();
        });

        $this->registerCheck('cache', function () {
            return $this->cacheCheck->execute();
        });

        $this->registerCheck('external_api', function () {
            return $this->externalApiCheck->execute();
        });
    }

    public function registerCheck(string $name, callable $check): void
    {
        $this->checks[$name] = $check;
    }

    public function check(?string $checkName = null): HealthCheckResult
    {
        if (self::HEALTH_CHECK_CACHE_RESULTS && $this->hasValidCache()) {
            $this->logger->debug('Returning cached health check result', [
                'cached_at' => $this->cacheTimestamp,
                'cache_ttl' => self::HEALTH_CHECK_CACHE_TTL,
            ]);
            return $this->cachedResult;
        }

        if ($checkName !== null) {
            return $this->runSingleCheck($checkName);
        }

        return $this->runAllChecks();
    }

    private function runSingleCheck(string $name): HealthCheckResult
    {
        if (!isset($this->checks[$name])) {
            return new HealthCheckResult(false, "Check '{$name}' not found");
        }

        $attempts = 0;
        $lastError = null;

        while ($attempts < self::HEALTH_CHECK_RETRIES) {
            try {
                $result = ($this->checks[$name])();

                if (self::HEALTH_CHECK_ENABLE_DETAILED_RESULTS) {
                    $this->logger->info('Health check executed', [
                        'check' => $name,
                        'result' => $result->isHealthy(),
                        'attempts' => $attempts + 1,
                        'timeout' => self::HEALTH_CHECK_TIMEOUT,
                        'interval' => self::HEALTH_CHECK_INTERVAL,
                    ]);
                }

                $this->recordHealthHistory($name, $result->isHealthy());

                return $result;
            } catch (\Exception $e) {
                $attempts++;
                $lastError = $e;

                $this->logger->warning('Health check failed', [
                    'check' => $name,
                    'attempt' => $attempts,
                    'max_retries' => self::HEALTH_CHECK_RETRIES,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::HEALTH_CHECK_RETRY_DELAY,
                ]);

                if ($attempts < self::HEALTH_CHECK_RETRIES) {
                    usleep(self::HEALTH_CHECK_RETRY_DELAY * 1000 * $attempts);
                }
            }
        }

        $result = new HealthCheckResult(false, $lastError?->getMessage() ?? 'Check failed');
        $this->recordHealthHistory($name, false);

        return $result;
    }

    private function runAllChecks(): HealthCheckResult
    {
        $results = [];
        $allHealthy = true;

        foreach ($this->checks as $name => $check) {
            $result = $this->runSingleCheck($name);
            $results[$name] = $result;

            if (!$result->isHealthy()) {
                $allHealthy = false;
            }
        }

        $summary = $allHealthy ? 'All checks passed' : 'Some checks failed';

        if (self::HEALTH_CHECK_INCLUDE_DEPENDENCIES) {
            $summary .= sprintf(' (%d/%d healthy)', count(array_filter($results, fn($r) => $r->isHealthy())), count($results));
        }

        $result = new HealthCheckResult($allHealthy, $summary, $results);

        if (self::HEALTH_CHECK_CACHE_RESULTS) {
            $this->cachedResult = $result;
            $this->cacheTimestamp = time();
        }

        return $result;
    }

    private function recordHealthHistory(string $checkName, bool $isHealthy): void
    {
        if (!isset($this->healthHistory[$checkName])) {
            $this->healthHistory[$checkName] = [];
        }

        $this->healthHistory[$checkName][] = [
            'healthy' => $isHealthy,
            'timestamp' => time(),
        ];

        if (count($this->healthHistory[$checkName]) > 100) {
            array_shift($this->healthHistory[$checkName]);
        }
    }

    private function hasValidCache(): bool
    {
        if ($this->cachedResult === null || $this->cacheTimestamp === null) {
            return false;
        }

        return (time() - $this->cacheTimestamp) < self::HEALTH_CHECK_CACHE_TTL;
    }

    public function getFailureThreshold(): int
    {
        return self::HEALTH_CHECK_FAILURE_THRESHOLD;
    }

    public function getSuccessThreshold(): int
    {
        return self::HEALTH_CHECK_SUCCESS_THRESHOLD;
    }

    public function isHealthy(string $checkName): bool
    {
        if (!isset($this->healthHistory[$checkName])) {
            return false;
        }

        $recent = array_slice($this->healthHistory[$checkName], -self::HEALTH_CHECK_SUCCESS_THRESHOLD);

        return count($recent) >= self::HEALTH_CHECK_SUCCESS_THRESHOLD &&
               array_all($recent, fn($h) => $h['healthy']);
    }
}
