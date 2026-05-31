<?php
declare(strict_types=1);

namespace App\Api\GraphQL;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class OrderGraphQLClient
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private string $endpoint;
    private string $apiKey;
    private array $cache = [];
    private int $cacheTtl = 300;
    private bool $cacheEnabled = true;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->endpoint = $config->get('graphql.orders.endpoint');
        $this->apiKey = $config->get('graphql.orders.api_key');
        
        $this->httpClient = new Client([
            'base_uri' => $this->endpoint,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
        
        $this->cacheEnabled = (bool)$config->get('graphql.orders.cache_enabled', true);
        $this->cacheTtl = (int)$config->get('graphql.orders.cache_ttl', 300);
    }

    public function query(string $query, array $variables = [], array $extensions = []): array
    {
        $cacheKey = $this->generateCacheKey($query, $variables);
        
        if ($this->cacheEnabled && $this->isQuery($query)) {
            if (isset($this->cache[$cacheKey]) && $this->cache[$cacheKey]['expires_at'] > time()) {
                $this->logger->debug('Order GraphQL cache hit', ['key' => $cacheKey]);
                return $this->cache[$cacheKey]['data'];
            }
        }
        
        try {
            $response = $this->httpClient->post('', [
                'json' => [
                    'query' => $query,
                    'variables' => $variables,
                    'extensions' => $extensions,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (isset($data['errors']) && !empty($data['errors'])) {
                $this->logger->error('Order GraphQL query errors', [
                    'errors' => $data['errors'],
                    'query' => $this->simplifyQuery($query),
                ]);
                throw new GraphQLException('GraphQL query failed: ' . json_encode($data['errors']));
            }
            
            if ($this->cacheEnabled && $this->isQuery($query)) {
                $this->cache[$cacheKey] = [
                    'data' => $data['data'],
                    'expires_at' => time() + $this->cacheTtl,
                ];
            }
            
            $this->logger->debug('Order GraphQL query executed', [
                'query' => $this->simplifyQuery($query),
                'variables_count' => count($variables),
            ]);
            
            return $data['data'];
            
        } catch (GuzzleException $e) {
            $this->logger->error('Order GraphQL request failed', [
                'error' => $e->getMessage(),
                'query' => $this->simplifyQuery($query),
            ]);
            throw new GraphQLException('GraphQL request failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function mutate(string $mutation, array $variables = []): array
    {
        try {
            $response = $this->httpClient->post('', [
                'json' => [
                    'query' => $mutation,
                    'variables' => $variables,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (isset($data['errors']) && !empty($data['errors'])) {
                $this->logger->error('Order GraphQL mutation errors', [
                    'errors' => $data['errors'],
                    'mutation' => $this->simplifyQuery($mutation),
                ]);
                throw new GraphQLException('GraphQL mutation failed: ' . json_encode($data['errors']));
            }
            
            $this->invalidateOrderCache($variables);
            
            $this->logger->info('Order GraphQL mutation executed', [
                'mutation' => $this->simplifyQuery($mutation),
            ]);
            
            return $data['data'];
            
        } catch (GuzzleException $e) {
            $this->logger->error('Order GraphQL mutation failed', [
                'error' => $e->getMessage(),
                'mutation' => $this->simplifyQuery($mutation),
            ]);
            throw new GraphQLException('GraphQL mutation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getOrder(int $id): ?array
    {
        $result = $this->query(
            'query GetOrder($id: ID!) { order(id: $id) { id status totalAmount items { id quantity price product { name } } customer { name email } createdAt } }',
            ['id' => (string)$id]
        );
        
        return $result['order'] ?? null;
    }

    public function getOrders(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $result = $this->query(
            'query GetOrders($filters: OrderFilters, $page: Int, $perPage: Int) { orders(filters: $filters, page: $page, perPage: $perPage) { data { id status totalAmount createdAt } pagination { total pages current } } }',
            ['filters' => $filters, 'page' => $page, 'perPage' => $perPage]
        );
        
        return $result['orders'];
    }

    public function createOrder(array $input): array
    {
        $result = $this->mutate(
            'mutation CreateOrder($input: CreateOrderInput!) { createOrder(input: $input) { id status totalAmount } }',
            ['input' => $input]
        );
        
        return $result['createOrder'];
    }

    public function updateOrderStatus(int $id, string $status): array
    {
        $result = $this->mutate(
            'mutation UpdateOrderStatus($id: ID!, $status: OrderStatus!) { updateOrderStatus(id: $id, status: $status) { id status updatedAt } }',
            ['id' => (string)$id, 'status' => $status]
        );
        
        return $result['updateOrderStatus'];
    }

    public function cancelOrder(int $id, string $reason): bool
    {
        $result = $this->mutate(
            'mutation CancelOrder($id: ID!, $reason: String!) { cancelOrder(id: $id, reason: $reason) { success } }',
            ['id' => (string)$id, 'reason' => $reason]
        );
        
        return $result['cancelOrder']['success'] ?? false;
    }

    public function getOrderHistory(int $customerId, int $limit = 10): array
    {
        $result = $this->query(
            'query GetOrderHistory($customerId: ID!, $limit: Int) { orderHistory(customerId: $customerId, limit: $limit) { data { id status totalAmount createdAt } } }',
            ['customerId' => (string)$customerId, 'limit' => $limit]
        );
        
        return $result['orderHistory']['data'];
    }

    private function isQuery(string $operation): bool
    {
        return str_starts_with(trim($operation), 'query');
    }

    private function generateCacheKey(string $query, array $variables): string
    {
        return md5($query . json_encode($variables));
    }

    private function invalidateOrderCache(array $variables): void
    {
        if (isset($variables['id'])) {
            $id = is_array($variables['id']) ? $variables['id'][0] : $variables['id'];
            $keyPattern = 'order(id:' . $id . ')';
            foreach ($this->cache as $key => $entry) {
                if (str_contains($key, $keyPattern)) {
                    unset($this->cache[$key]);
                }
            }
        } else {
            $this->cache = [];
        }
    }

    private function simplifyQuery(string $query): string
    {
        return preg_replace('/\s+/', ' ', trim(substr($query, 0, 100)));
    }

    public function clearCache(): void
    {
        $this->cache = [];
        $this->logger->info('Order GraphQL cache cleared');
    }
}
