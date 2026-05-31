<?php
declare(strict_types=1);

namespace App\Api;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;

abstract class AbstractRestApiClient
{
    protected Client $httpClient;
    protected LoggerInterface $logger;

    abstract protected function getServiceName(): string;
    abstract protected function getBaseUrl(): string;
    abstract protected function getApiKey(): string;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        
        $this->httpClient = new Client([
            'base_uri' => $this->getBaseUrl(),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getApiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    protected function get(string $uri, array $query = []): array
    {
        try {
            $response = $this->httpClient->get($uri, ['query' => $query]);
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->handleError('GET', $uri, $e);
            throw $e;
        }
    }

    protected function post(string $uri, array $data = []): array
    {
        try {
            $response = $this->httpClient->post($uri, ['json' => $data]);
            
            $this->logOperation('POST', $uri, $this->getIdFromResponse($response));
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->handleError('POST', $uri, $e);
            throw $e;
        }
    }

    protected function put(string $uri, array $data = []): array
    {
        try {
            $response = $this->httpClient->put($uri, ['json' => $data]);
            
            $this->logOperation('PUT', $uri, $this->getIdFromResponse($response));
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->handleError('PUT', $uri, $e);
            throw $e;
        }
    }

    protected function patch(string $uri, array $data = []): array
    {
        try {
            $response = $this->httpClient->patch($uri, ['json' => $data]);
            
            $this->logOperation('PATCH', $uri, $this->getIdFromResponse($response));
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->handleError('PATCH', $uri, $e);
            throw $e;
        }
    }

    protected function delete(string $uri, array $query = []): bool
    {
        try {
            $response = $this->httpClient->delete($uri, ['query' => $query]);
            
            $this->logOperation('DELETE', $uri);
            
            return $response->getStatusCode() === 204;
        } catch (GuzzleException $e) {
            $this->handleError('DELETE', $uri, $e);
            throw $e;
        }
    }

    protected function getResource(string $uri): ?array
    {
        try {
            $response = $this->httpClient->get($uri);
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            $this->handleError('GET', $uri, $e);
            throw $e;
        }
    }

    protected function getCollection(string $uri, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        try {
            $query = array_merge($filters, [
                'page' => $page,
                'per_page' => $perPage,
            ]);
            
            $response = $this->httpClient->get($uri, ['query' => $query]);
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->handleError('GET', $uri, $e);
            throw $e;
        }
    }

    protected function parseResponse(Response $response): array
    {
        $body = $response->getBody()->getContents();
        return json_decode($body, true) ?? [];
    }

    protected function getIdFromResponse(Response $response): ?string
    {
        $data = $this->parseResponse($response);
        return $data['id'] ?? null;
    }

    protected function handleError(string $method, string $uri, GuzzleException $e): void
    {
        $this->logger->error($this->getServiceName() . ' API error', [
            'method' => $method,
            'uri' => $uri,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);
    }

    protected function logOperation(string $method, string $uri, ?string $resourceId = null): void
    {
        $context = [
            'method' => $method,
            'uri' => $uri,
        ];
        
        if ($resourceId !== null) {
            $context['resource_id'] = $resourceId;
        }
        
        $this->logger->info($this->getServiceName() . ' API operation', $context);
    }
}
