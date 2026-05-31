<?php
declare(strict_types=1);

namespace App\Http;

use App\Logging\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class RetryableHttpClient
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private int $maxRetries;
    private int $baseDelayMs;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        int $maxRetries = 3,
        int $baseDelayMs = 100
    ) {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->maxRetries = $maxRetries;
        $this->baseDelayMs = $baseDelayMs;
    }

    public function request(
        string $method,
        string $url,
        array $options = [],
        int $attempt = 0
    ): array {
        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            
            if ($this->isRetryableStatus($statusCode) && $attempt < $this->maxRetries) {
                $reason = $statusCode >= 500 ? 'server_error' : 'rate_limit';
                return $this->retry($method, $url, $options, $attempt, $reason);
            }
            
            if ($statusCode >= 400) {
                return $response->toArray();
            }
            
            return $response->toArray();
            
        } catch (TransportExceptionInterface $e) {
            if ($attempt < $this->maxRetries) {
                return $this->retry($method, $url, $options, $attempt, 'transport');
            }
            
            $this->logger->error('HTTP request failed after retries', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    private function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode >= 500 || $statusCode === 429;
    }

    private function retry(
        string $method,
        string $url,
        array $options,
        int $attempt,
        string $reason
    ): array {
        $delay = $this->calculateBackoffDelay($attempt);
        
        $this->logger->warning('HTTP request retrying', [
            'method' => $method,
            'url' => $url,
            'attempt' => $attempt + 1,
            'delay_ms' => $delay,
            'reason' => $reason,
        ]);
        
        usleep($delay * 1000);
        
        return $this->request($method, $url, $options, $attempt + 1);
    }

    private function calculateBackoffDelay(int $attempt): int
    {
        $exponentialDelay = $this->baseDelayMs * pow(2, $attempt);
        $jitter = random_int(0, (int)($exponentialDelay * 0.1));
        return $exponentialDelay + $jitter;
    }
}
