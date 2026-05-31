<?php
declare(strict_types=1);

namespace App\Http;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

final class CircuitBreakerHttpClient
{
    private ConfigManager $config;
    private LoggerInterface $logger;
    private int $timeout;
    private int $connectTimeout;
    private int $failureThreshold;
    private int $recoveryTimeout;
    private int $failureCount = 0;
    private string $state = 'closed';
    private ?int $lastFailureTime = null;
    private string $serviceName;

    public function __construct(
        string $serviceName,
        ConfigManager $config,
        LoggerInterface $logger,
        int $timeout = 30,
        int $connectTimeout = 10,
        int $failureThreshold = 5,
        int $recoveryTimeout = 60
    ) {
        $this->serviceName = $serviceName;
        $this->config = $config;
        $this->logger = $logger;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->failureThreshold = $failureThreshold;
        $this->recoveryTimeout = $recoveryTimeout;
    }

    public function request(string $method, string $url, array $options = []): array
    {
        if (!$this->isRequestAllowed()) {
            $this->logger->warning("{$this->serviceName} circuit breaker is open");
            throw new \RuntimeException('Circuit breaker is open');
        }
        
        try {
            $result = $this->doRequest($method, $url, $options);
            $this->recordSuccess();
            return $result;
        } catch (\Exception $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    private function doRequest(string $method, string $url, array $options = []): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new \RuntimeException("{$this->serviceName} request failed");
        }
        
        return ['status' => 'success', 'data' => $response];
    }

    private function isRequestAllowed(): bool
    {
        if ($this->state === 'closed') {
            return true;
        }
        
        if ($this->state === 'open') {
            if ($this->lastFailureTime !== null && 
                (time() - $this->lastFailureTime) >= $this->recoveryTimeout) {
                $this->state = 'half-open';
                $this->logger->info("{$this->serviceName} circuit breaker entering half-open state");
                return true;
            }
            return false;
        }
        
        return true;
    }

    private function recordSuccess(): void
    {
        $this->failureCount = 0;
        
        if ($this->state === 'half-open') {
            $this->state = 'closed';
            $this->logger->info("{$this->serviceName} circuit breaker closed");
        }
    }

    private function recordFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureTime = time();
        
        if ($this->failureCount >= $this->failureThreshold) {
            $this->state = 'open';
            $this->logger->warning("{$this->serviceName} circuit breaker opened", [
                'failures' => $this->failureCount,
            ]);
        }
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }
}
