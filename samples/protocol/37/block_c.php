<?php
declare(strict_types=1);

namespace App\Http;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

final class ThirdPartyIntegrationClient
{
    private ConfigManager $config;
    private LoggerInterface $logger;
    private int $timeout = 30;
    private int $connectTimeout = 10;
    private int $failureThreshold = 5;
    private int $recoveryTimeout = 60;
    private int $failureCount = 0;
    private string $state = 'closed';
    private ?int $lastFailureTime = null;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->timeout = (int)$config->get('http.third_party.timeout', 30);
        $this->connectTimeout = (int)$config->get('http.third_party.connect_timeout', 10);
    }

    public function request(string $method, string $url, array $options = []): array
    {
        if (!$this->isRequestAllowed()) {
            $this->logger->warning('Third party integration circuit breaker is open');
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
            throw new \RuntimeException('Third party integration request failed');
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
                $this->logger->info('Third party integration circuit breaker entering half-open state');
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
            $this->logger->info('Third party integration circuit breaker closed');
        }
    }

    private function recordFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureTime = time();
        
        if ($this->failureCount >= $this->failureThreshold) {
            $this->state = 'open';
            $this->logger->warning('Third party integration circuit breaker opened', [
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
