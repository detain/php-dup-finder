<?php
declare(strict_types=1);

namespace App\Api\GraphQL;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

abstract class AbstractGraphQLClient
{
    protected Client $httpClient;
    protected LoggerInterface $logger;
    protected string $endpoint;
    protected string $apiKey;
    protected array $cache = [];
    protected int $cacheTtl = 300;
    protected bool $cacheEnabled = true;

    abstract protected function getServiceName(): string;

    public function __construct(ConfigManager $config, LoggerInterface $logger)
    {
        $this->logger = $logger;
        
        $prefix = 'graphql.' . $this->getServiceName();
        $this->endpoint = $config->get($prefix . '.endpoint');
        $this->apiKey = $config->get($prefix . '.api_key');
        
        $this->httpClient = new Client([
            'base_uri' => $this->endpoint,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
        
        $this->cacheEnabled = (bool)$config->get($prefix . '.cache_enabled', true);
        $this->cacheTtl = (int)$config->get($prefix . '.cache_ttl', 300);
    }

    public function query(string $query, array $variables = [], array $extensions = []): array
    {
        $cacheKey = $this->generateCacheKey($query, $variables);
        
        if ($this->cacheEnabled && $this->isQuery($query)) {
            if (isset($this->cache[$cacheKey]) && $this->cache[$cacheKey]['expires_at'] > time()) {
                $this->logger->debug($this->getServiceName() . ' GraphQL cache hit', ['key' => $cacheKey]);
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
                $this->logger->error($this->getServiceName() . ' GraphQL query errors', [
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
            
            $this->logger->debug($this->getServiceName() . ' GraphQL query executed', [
                'query' => $this->simplifyQuery($query),
                'variables_count' => count($variables),
            ]);
            
            return $data['data'];
            
        } catch (GuzzleException $e) {
            $this->logger->error($this->getServiceName() . ' GraphQL request failed', [
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
                $this->logger->error($this->getServiceName() . ' GraphQL mutation errors', [
                    'errors' => $data['errors'],
                    'mutation' => $this->simplifyQuery($mutation),
                ]);
                throw new GraphQLException('GraphQL mutation failed: ' . json_encode($data['errors']));
            }
            
            $this->invalidateCache($variables);
            
            $this->logger->info($this->getServiceName() . ' GraphQL mutation executed', [
                'mutation' => $this->simplifyQuery($mutation),
            ]);
            
            return $data['data'];
            
        } catch (GuzzleException $e) {
            $this->logger->error($this->getServiceName() . ' GraphQL mutation failed', [
                'error' => $e->getMessage(),
                'mutation' => $this->simplifyQuery($mutation),
            ]);
            throw new GraphQLException('GraphQL mutation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function isQuery(string $operation): bool
    {
        return str_starts_with(trim($operation), 'query');
    }

    protected function generateCacheKey(string $query, array $variables): string
    {
        return md5($query . json_encode($variables));
    }

    protected function invalidateCache(array $variables): void
    {
        if (isset($variables['id'])) {
            $id = is_array($variables['id']) ? $variables['id'][0] : $variables['id'];
            $keyPattern = strtolower($this->getServiceName()) . '(id:' . $id . ')';
            foreach ($this->cache as $key => $entry) {
                if (str_contains($key, $keyPattern)) {
                    unset($this->cache[$key]);
                }
            }
        } else {
            $this->cache = [];
        }
    }

    protected function simplifyQuery(string $query): string
    {
        return preg_replace('/\s+/', ' ', trim(substr($query, 0, 100)));
    }

    public function clearCache(): void
    {
        $this->cache = [];
        $this->logger->info($this->getServiceName() . ' GraphQL cache cleared');
    }
}
