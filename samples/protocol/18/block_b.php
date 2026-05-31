<?php
declare(strict_types=1);

namespace App\Api\GraphQL;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class ProductGraphQLClient
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
        $this->endpoint = $config->get('graphql.products.endpoint');
        $this->apiKey = $config->get('graphql.products.api_key');
        
        $this->httpClient = new Client([
            'base_uri' => $this->endpoint,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
        
        $this->cacheEnabled = (bool)$config->get('graphql.products.cache_enabled', true);
        $this->cacheTtl = (int)$config->get('graphql.products.cache_ttl', 300);
    }

    public function query(string $query, array $variables = [], array $extensions = []): array
    {
        $cacheKey = $this->generateCacheKey($query, $variables);
        
        if ($this->cacheEnabled && $this->isQuery($query)) {
            if (isset($this->cache[$cacheKey]) && $this->cache[$cacheKey]['expires_at'] > time()) {
                $this->logger->debug('Product GraphQL cache hit', ['key' => $cacheKey]);
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
                $this->logger->error('Product GraphQL query errors', [
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
            
            $this->logger->debug('Product GraphQL query executed', [
                'query' => $this->simplifyQuery($query),
                'variables_count' => count($variables),
            ]);
            
            return $data['data'];
            
        } catch (GuzzleException $e) {
            $this->logger->error('Product GraphQL request failed', [
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
                $this->logger->error('Product GraphQL mutation errors', [
                    'errors' => $data['errors'],
                    'mutation' => $this->simplifyQuery($mutation),
                ]);
                throw new GraphQLException('GraphQL mutation failed: ' . json_encode($data['errors']));
            }
            
            $this->invalidateProductCache($variables);
            
            $this->logger->info('Product GraphQL mutation executed', [
                'mutation' => $this->simplifyQuery($mutation),
            ]);
            
            return $data['data'];
            
        } catch (GuzzleException $e) {
            $this->logger->error('Product GraphQL mutation failed', [
                'error' => $e->getMessage(),
                'mutation' => $this->simplifyQuery($mutation),
            ]);
            throw new GraphQLException('GraphQL mutation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getProduct(int $id): ?array
    {
        $result = $this->query(
            'query GetProduct($id: ID!) { product(id: $id) { id sku name price description category { id name } stock status } }',
            ['id' => (string)$id]
        );
        
        return $result['product'] ?? null;
    }

    public function getProducts(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $result = $this->query(
            'query GetProducts($filters: ProductFilters, $page: Int, $perPage: Int) { products(filters: $filters, page: $page, perPage: $perPage) { data { id sku name price status } pagination { total pages current } } }',
            ['filters' => $filters, 'page' => $page, 'perPage' => $perPage]
        );
        
        return $result['products'];
    }

    public function createProduct(array $input): array
    {
        $result = $this->mutate(
            'mutation CreateProduct($input: CreateProductInput!) { createProduct(input: $input) { id sku name price } }',
            ['input' => $input]
        );
        
        return $result['createProduct'];
    }

    public function updateProduct(int $id, array $input): array
    {
        $result = $this->mutate(
            'mutation UpdateProduct($id: ID!, $input: UpdateProductInput!) { updateProduct(id: $id, input: $input) { id sku name price } }',
            ['id' => (string)$id, 'input' => $input]
        );
        
        return $result['updateProduct'];
    }

    public function deleteProduct(int $id): bool
    {
        $result = $this->mutate(
            'mutation DeleteProduct($id: ID!) { deleteProduct(id: $id) { success } }',
            ['id' => (string)$id]
        );
        
        return $result['deleteProduct']['success'] ?? false;
    }

    public function searchProducts(string $query, int $limit = 10): array
    {
        $result = $this->query(
            'query SearchProducts($query: String!, $limit: Int) { searchProducts(query: $query, limit: $limit) { id sku name price thumbnail } }',
            ['query' => $query, 'limit' => $limit]
        );
        
        return $result['searchProducts'];
    }

    public function getProductReviews(int $productId, int $limit = 10): array
    {
        $result = $this->query(
            'query GetProductReviews($productId: ID!, $limit: Int) { productReviews(productId: $productId, limit: $limit) { data { id rating content author { name avatar } } averageRating } }',
            ['productId' => (string)$productId, 'limit' => $limit]
        );
        
        return $result['productReviews'];
    }

    private function isQuery(string $operation): bool
    {
        return str_starts_with(trim($operation), 'query');
    }

    private function generateCacheKey(string $query, array $variables): string
    {
        return md5($query . json_encode($variables));
    }

    private function invalidateProductCache(array $variables): void
    {
        if (isset($variables['id'])) {
            $id = is_array($variables['id']) ? $variables['id'][0] : $variables['id'];
            $keyPattern = 'product(id:' . $id . ')';
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
        $this->logger->info('Product GraphQL cache cleared');
    }
}
