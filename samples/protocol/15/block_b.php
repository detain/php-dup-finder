<?php
declare(strict_types=1);

namespace App\Fulfillment\Services;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;

final class FulfillmentApiClient
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
        $this->baseUrl = $config->get('fulfillment.api_url');
        $this->apiKey = $config->get('fulfillment.api_key');
        
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

    public function getOrder(string $orderId): ?array
    {
        try {
            $response = $this->httpClient->get('/orders/' . $orderId);
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('get_order', $e);
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function getOrders(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        try {
            $query = array_merge($filters, [
                'page' => $page,
                'per_page' => $perPage,
            ]);
            
            $response = $this->httpClient->get('/orders', [
                'query' => $query,
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('get_orders', $e);
            throw $e;
        }
    }

    public function createOrder(array $orderData): array
    {
        try {
            $response = $this->httpClient->post('/orders', [
                'json' => $orderData,
            ]);
            
            $this->logger->info('Order created via API', [
                'order_id' => $this->getIdFromResponse($response),
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('create_order', $e);
            throw $e;
        }
    }

    public function updateOrder(string $orderId, array $orderData): array
    {
        try {
            $response = $this->httpClient->put('/orders/' . $orderId, [
                'json' => $orderData,
            ]);
            
            $this->logger->info('Order updated via API', [
                'order_id' => $orderId,
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('update_order', $e);
            throw $e;
        }
    }

    public function cancelOrder(string $orderId, string $reason): bool
    {
        try {
            $response = $this->httpClient->post('/orders/' . $orderId . '/cancel', [
                'json' => ['reason' => $reason],
            ]);
            
            $this->logger->info('Order cancelled via API', [
                'order_id' => $orderId,
                'reason' => $reason,
            ]);
            
            return in_array($response->getStatusCode(), [200, 204]);
        } catch (GuzzleException $e) {
            $this->logError('cancel_order', $e);
            throw $e;
        }
    }

    public function getFulfillmentOrder(string $orderId): ?array
    {
        try {
            $response = $this->httpClient->get('/fulfillment/orders/' . $orderId);
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('get_fulfillment_order', $e);
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function submitForFulfillment(string $orderId, array $options = []): array
    {
        try {
            $response = $this->httpClient->post('/fulfillment/orders/' . $orderId . '/submit', [
                'json' => $options,
            ]);
            
            $this->logger->info('Order submitted for fulfillment via API', [
                'order_id' => $orderId,
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('submit_fulfillment', $e);
            throw $e;
        }
    }

    public function getFulfillmentStatus(string $fulfillmentId): array
    {
        try {
            $response = $this->httpClient->get('/fulfillment/' . $fulfillmentId . '/status');
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('get_fulfillment_status', $e);
            throw $e;
        }
    }

    public function cancelFulfillment(string $fulfillmentId, string $reason): bool
    {
        try {
            $response = $this->httpClient->delete('/fulfillment/' . $fulfillmentId, [
                'query' => ['reason' => $reason],
            ]);
            
            $this->logger->info('Fulfillment cancelled via API', [
                'fulfillment_id' => $fulfillmentId,
                'reason' => $reason,
            ]);
            
            return $response->getStatusCode() === 204;
        } catch (GuzzleException $e) {
            $this->logError('cancel_fulfillment', $e);
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
        $this->logger->error('Fulfillment API error', [
            'operation' => $operation,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);
    }
}
