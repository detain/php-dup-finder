<?php
declare(strict_types=1);

namespace App\Http;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class RestApiClient
{
    private HttpClientInterface $httpClient;
    private ConfigManager $config;
    private LoggerInterface $logger;
    private int $maxRetries = 3;
    private int $baseDelayMs = 100;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->httpClient = HttpClient::create();
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->requestWithRetry('GET', $url, $headers);
    }

    public function post(string $url, array $data, array $headers = []): array
    {
        return $this->requestWithRetry('POST', $url, $headers, $data);
    }

    public function put(string $url, array $data, array $headers = []): array
    {
        return $this->requestWithRetry('PUT', $url, $headers, $data);
    }

    public function delete(string $url, array $headers = []): array
    {
        return $this->requestWithRetry('DELETE', $url, $headers);
    }

    private function requestWithRetry(
        string $method,
        string $url,
        array $headers = [],
        ?array $data = null,
        int $attempt = 0
    ): array {
        try {
            $options = ['headers' => $headers];
            
            if ($data !== null) {
                $options['json'] = $data;
            }
            
            $response = $this->httpClient->request($method, $url, $options);
            
            if ($response->getStatusCode() >= 500 && $attempt < $this->maxRetries) {
                return $this->handleRetry($method, $url, $headers, $data, $attempt, 'server_error');
            }
            
            if ($response->getStatusCode() === 429 && $attempt < $this->maxRetries) {
                return $this->handleRetry($method, $url, $headers, $data, $attempt, 'rate_limit');
            }
            
            if ($response->getStatusCode() >= 400) {
                return $response->toArray();
            }
            
            return $response->toArray();
            
        } catch (TransportExceptionInterface $e) {
            if ($attempt < $this->maxRetries) {
                return $this->handleRetry($method, $url, $headers, $data, $attempt, 'transport');
            }
            
            $this->logger->error('REST request failed after retries', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    private function handleRetry(
        string $method,
        string $url,
        array $headers,
        ?array $data,
        int $attempt,
        string $reason
    ): array {
        $delay = $this->calculateBackoffDelay($attempt);
        
        $this->logger->warning('REST request retrying', [
            'method' => $method,
            'url' => $url,
            'attempt' => $attempt + 1,
            'delay_ms' => $delay,
            'reason' => $reason,
        ]);
        
        usleep($delay * 1000);
        
        return $this->requestWithRetry($method, $url, $headers, $data, $attempt + 1);
    }

    private function calculateBackoffDelay(int $attempt): int
    {
        $exponentialDelay = $this->baseDelayMs * pow(2, $attempt);
        $jitter = random_int(0, (int)($exponentialDelay * 0.1));
        return $exponentialDelay + $jitter;
    }
}
