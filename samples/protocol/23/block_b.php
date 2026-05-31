<?php
declare(strict_types=1);

namespace App\Http;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class GraphQLClient
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

    public function query(string $query, array $variables = [], array $headers = []): array
    {
        return $this->requestWithRetry($query, $variables, $headers);
    }

    public function mutate(string $mutation, array $variables = [], array $headers = []): array
    {
        return $this->requestWithRetry($mutation, $variables, $headers);
    }

    private function requestWithRetry(
        string $query,
        array $variables = [],
        array $headers = [],
        int $attempt = 0
    ): array {
        try {
            $options = [
                'headers' => array_merge($headers, ['Content-Type' => 'application/json']),
                'json' => [
                    'query' => $query,
                    'variables' => $variables,
                ],
            ];
            
            $response = $this->httpClient->request('POST', $this->config->get('graphql.endpoint'), $options);
            
            if ($response->getStatusCode() >= 500 && $attempt < $this->maxRetries) {
                return $this->handleRetry($query, $variables, $headers, $attempt, 'server_error');
            }
            
            if ($response->getStatusCode() === 429 && $attempt < $this->maxRetries) {
                return $this->handleRetry($query, $variables, $headers, $attempt, 'rate_limit');
            }
            
            if ($response->getStatusCode() >= 400) {
                $body = $response->toArray();
                throw new \RuntimeException($body['errors'][0]['message'] ?? 'GraphQL error');
            }
            
            return $response->toArray();
            
        } catch (TransportExceptionInterface $e) {
            if ($attempt < $this->maxRetries) {
                return $this->handleRetry($query, $variables, $headers, $attempt, 'transport');
            }
            
            $this->logger->error('GraphQL request failed after retries', [
                'query' => substr($query, 0, 100),
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        } catch (\RuntimeException $e) {
            throw $e;
        }
    }

    private function handleRetry(
        string $query,
        array $variables,
        array $headers,
        int $attempt,
        string $reason
    ): array {
        $delay = $this->calculateBackoffDelay($attempt);
        
        $this->logger->warning('GraphQL request retrying', [
            'attempt' => $attempt + 1,
            'delay_ms' => $delay,
            'reason' => $reason,
        ]);
        
        usleep($delay * 1000);
        
        return $this->requestWithRetry($query, $variables, $headers, $attempt + 1);
    }

    private function calculateBackoffDelay(int $attempt): int
    {
        $exponentialDelay = $this->baseDelayMs * pow(2, $attempt);
        $jitter = random_int(0, (int)($exponentialDelay * 0.1));
        return $exponentialDelay + $jitter;
    }
}
