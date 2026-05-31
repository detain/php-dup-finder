<?php
declare(strict_types=1);

namespace App\Returns\Services;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;

final class ReturnsApiClient
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private string $baseUrl;
    private string $apiKey;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->baseUrl = $config->get('returns.api_url');
        $this->apiKey = $config->get('returns.api_key');
        
        $this->httpClient = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function getReturn(string $returnId): ?array
    {
        try {
            $response = $this->httpClient->get('/returns/' . $returnId);
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('get_return', $e);
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function getReturns(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        try {
            $query = array_merge($filters, [
                'page' => $page,
                'per_page' => $perPage,
            ]);
            
            $response = $this->httpClient->get('/returns', [
                'query' => $query,
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('get_returns', $e);
            throw $e;
        }
    }

    public function createReturn(array $returnData): array
    {
        try {
            $response = $this->httpClient->post('/returns', [
                'json' => $returnData,
            ]);
            
            $this->logger->info('Return created via API', [
                'return_id' => $this->getIdFromResponse($response),
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('create_return', $e);
            throw $e;
        }
    }

    public function updateReturn(string $returnId, array $returnData): array
    {
        try {
            $response = $this->httpClient->put('/returns/' . $returnId, [
                'json' => $returnData,
            ]);
            
            $this->logger->info('Return updated via API', [
                'return_id' => $returnId,
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('update_return', $e);
            throw $e;
        }
    }

    public function cancelReturn(string $returnId, string $reason): bool
    {
        try {
            $response = $this->httpClient->post('/returns/' . $returnId . '/cancel', [
                'json' => ['reason' => $reason],
            ]);
            
            $this->logger->info('Return cancelled via API', [
                'return_id' => $returnId,
                'reason' => $reason,
            ]);
            
            return in_array($response->getStatusCode(), [200, 204]);
        } catch (GuzzleException $e) {
            $this->logError('cancel_return', $e);
            throw $e;
        }
    }

    public function receiveReturn(string $returnId, array $items): array
    {
        try {
            $response = $this->httpClient->post('/returns/' . $returnId . '/receive', [
                'json' => ['items' => $items],
            ]);
            
            $this->logger->info('Return items received via API', [
                'return_id' => $returnId,
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('receive_return', $e);
            throw $e;
        }
    }

    public function inspectReturn(string $returnId, array $inspectionData): array
    {
        try {
            $response = $this->httpClient->post('/returns/' . $returnId . '/inspect', [
                'json' => $inspectionData,
            ]);
            
            $this->logger->info('Return inspected via API', [
                'return_id' => $returnId,
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('inspect_return', $e);
            throw $e;
        }
    }

    public function approveReturn(string $returnId, array $refundData): array
    {
        try {
            $response = $this->httpClient->post('/returns/' . $returnId . '/approve', [
                'json' => $refundData,
            ]);
            
            $this->logger->info('Return approved via API', [
                'return_id' => $returnId,
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('approve_return', $e);
            throw $e;
        }
    }

    public function rejectReturn(string $returnId, string $reason): bool
    {
        try {
            $response = $this->httpClient->post('/returns/' . $returnId . '/reject', [
                'json' => ['reason' => $reason],
            ]);
            
            $this->logger->info('Return rejected via API', [
                'return_id' => $returnId,
                'reason' => $reason,
            ]);
            
            return $response->getStatusCode() === 204;
        } catch (GuzzleException $e) {
            $this->logError('reject_return', $e);
            throw $e;
        }
    }

    private function parseResponse(Response $response): array
    {
        $body = $response->getBody()->getContents();
        return json_decode($body, true) ?? [];
    }

    private function getIdFromResponse(Response $response): ?string
    {
        $data = $this->parseResponse($response);
        return $data['id'] ?? null;
    }

    private function logError(string $operation, GuzzleException $e): void
    {
        $this->logger->error('Returns API error', [
            'operation' => $operation,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);
    }
}
