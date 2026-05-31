<?php
declare(strict_types=1);

namespace App\Api\GraphQL;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class UserGraphQLClient
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
        $this->endpoint = $config->get('graphql.users.endpoint');
        $this->apiKey = $config->get('graphql.users.api_key');
        
        $this->httpClient = new Client([
            'base_uri' => $this->endpoint,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
        
        $this->cacheEnabled = (bool)$config->get('graphql.users.cache_enabled', true);
        $this->cacheTtl = (int)$config->get('graphql.users.cache_ttl', 300);
    }

    public function query(string $query, array $variables = [], array $extensions = []): array
    {
        $cacheKey = $this->generateCacheKey($query, $variables);
        
        if ($this->cacheEnabled && $this->isQuery($query)) {
            if (isset($this->cache[$cacheKey]) && $this->cache[$cacheKey]['expires_at'] > time()) {
                $this->logger->debug('User GraphQL cache hit', ['key' => $cacheKey]);
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
                $this->logger->error('User GraphQL query errors', [
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
            
            $this->logger->debug('User GraphQL query executed', [
                'query' => $this->simplifyQuery($query),
                'variables_count' => count($variables),
            ]);
            
            return $data['data'];
            
        } catch (GuzzleException $e) {
            $this->logger->error('User GraphQL request failed', [
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
                $this->logger->error('User GraphQL mutation errors', [
                    'errors' => $data['errors'],
                    'mutation' => $this->simplifyQuery($mutation),
                ]);
                throw new GraphQLException('GraphQL mutation failed: ' . json_encode($data['errors']));
            }
            
            $this->invalidateUserCache($variables);
            
            $this->logger->info('User GraphQL mutation executed', [
                'mutation' => $this->simplifyQuery($mutation),
            ]);
            
            return $data['data'];
            
        } catch (GuzzleException $e) {
            $this->logger->error('User GraphQL mutation failed', [
                'error' => $e->getMessage(),
                'mutation' => $this->simplifyQuery($mutation),
            ]);
            throw new GraphQLException('GraphQL mutation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getUser(int $id): ?array
    {
        $result = $this->query(
            'query GetUser($id: ID!) { user(id: $id) { id name email createdAt } }',
            ['id' => (string)$id]
        );
        
        return $result['user'] ?? null;
    }

    public function getUsers(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $result = $this->query(
            'query GetUsers($filters: UserFilters, $page: Int, $perPage: Int) { users(filters: $filters, page: $page, perPage: $perPage) { data { id name email } pagination { total pages current } } }',
            ['filters' => $filters, 'page' => $page, 'perPage' => $perPage]
        );
        
        return $result['users'];
    }

    public function createUser(array $input): array
    {
        $result = $this->mutate(
            'mutation CreateUser($input: CreateUserInput!) { createUser(input: $input) { id name email } }',
            ['input' => $input]
        );
        
        return $result['createUser'];
    }

    public function updateUser(int $id, array $input): array
    {
        $result = $this->mutate(
            'mutation UpdateUser($id: ID!, $input: UpdateUserInput!) { updateUser(id: $id, input: $input) { id name email } }',
            ['id' => (string)$id, 'input' => $input]
        );
        
        return $result['updateUser'];
    }

    public function deleteUser(int $id): bool
    {
        $result = $this->mutate(
            'mutation DeleteUser($id: ID!) { deleteUser(id: $id) { success } }',
            ['id' => (string)$id]
        );
        
        return $result['deleteUser']['success'] ?? false;
    }

    public function searchUsers(string $query, int $limit = 10): array
    {
        $result = $this->query(
            'query SearchUsers($query: String!, $limit: Int) { searchUsers(query: $query, limit: $limit) { id name email avatar } }',
            ['query' => $query, 'limit' => $limit]
        );
        
        return $result['searchUsers'];
    }

    private function isQuery(string $operation): bool
    {
        return str_starts_with(trim($operation), 'query');
    }

    private function generateCacheKey(string $query, array $variables): string
    {
        return md5($query . json_encode($variables));
    }

    private function invalidateUserCache(array $variables): void
    {
        if (isset($variables['id'])) {
            $id = is_array($variables['id']) ? $variables['id'][0] : $variables['id'];
            $keyPattern = 'user(id:' . $id . ')';
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
        $this->logger->info('User GraphQL cache cleared');
    }
}
