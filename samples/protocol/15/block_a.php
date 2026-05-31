<?php
declare(strict_types=1);

namespace App\Inventory\Services;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;

final class InventoryApiClient
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
        $this->baseUrl = $config->get('inventory.api_url');
        $this->apiKey = $config->get('inventory.api_key');
        
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

    public function getProduct(string $productId): ?array
    {
        try {
            $response = $this->httpClient->get('/products/' . $productId);
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('get_product', $e);
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function getProducts(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        try {
            $query = array_merge($filters, [
                'page' => $page,
                'per_page' => $perPage,
            ]);
            
            $response = $this->httpClient->get('/products', [
                'query' => $query,
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('get_products', $e);
            throw $e;
        }
    }

    public function createProduct(array $productData): array
    {
        try {
            $response = $this->httpClient->post('/products', [
                'json' => $productData,
            ]);
            
            $this->logger->info('Product created via API', [
                'product_id' => $this->getIdFromResponse($response),
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('create_product', $e);
            throw $e;
        }
    }

    public function updateProduct(string $productId, array $productData): array
    {
        try {
            $response = $this->httpClient->put('/products/' . $productId, [
                'json' => $productData,
            ]);
            
            $this->logger->info('Product updated via API', [
                'product_id' => $productId,
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('update_product', $e);
            throw $e;
        }
    }

    public function deleteProduct(string $productId): bool
    {
        try {
            $response = $this->httpClient->delete('/products/' . $productId);
            
            $this->logger->info('Product deleted via API', [
                'product_id' => $productId,
            ]);
            
            return $response->getStatusCode() === 204;
        } catch (GuzzleException $e) {
            $this->logError('delete_product', $e);
            throw $e;
        }
    }

    public function updateStock(string $productId, array $stockData): array
    {
        try {
            $response = $this->httpClient->patch('/products/' . $productId . '/stock', [
                'json' => $stockData,
            ]);
            
            $this->logger->info('Stock updated via API', [
                'product_id' => $productId,
                'stock' => $stockData,
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('update_stock', $e);
            throw $e;
        }
    }

    public function getStockLevels(array $warehouseIds = []): array
    {
        try {
            $params = [];
            if (!empty($warehouseIds)) {
                $params['warehouse_ids'] = implode(',', $warehouseIds);
            }
            
            $response = $this->httpClient->get('/inventory/stock-levels', [
                'query' => $params,
            ]);
            
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            $this->logError('get_stock_levels', $e);
            throw $e;
        }
    }

    public function reserveStock(string $sku, int $quantity, string $orderId): bool
    {
        try {
            $response = $this->httpClient->post('/inventory/reservations', [
                'json' => [
                    'sku' => $sku,
                    'quantity' => $quantity,
                    'order_id' => $orderId,
                ],
            ]);
            
            $this->logger->info('Stock reserved via API', [
                'sku' => $sku,
                'quantity' => $quantity,
                'order_id' => $orderId,
            ]);
            
            return in_array($response->getStatusCode(), [200, 201]);
        } catch (GuzzleException $e) {
            $this->logError('reserve_stock', $e);
            throw $e;
        }
    }

    public function releaseReservation(string $reservationId): bool
    {
        try {
            $response = $this->httpClient->delete('/inventory/reservations/' . $reservationId);
            
            $this->logger->info('Stock reservation released via API', [
                'reservation_id' => $reservationId,
            ]);
            
            return $response->getStatusCode() === 204;
        } catch (GuzzleException $e) {
            $this->logError('release_reservation', $e);
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
        $this->logger->error('Inventory API error', [
            'operation' => $operation,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);
    }
}
