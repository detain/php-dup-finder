<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\ApiEndpointRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ApiResponseCacheHandler
{
    private const CACHE_PREFIX = 'api_response';
    private const DEFAULT_TTL = 300;
    private const STALE_TTL = 60;

    private const LIST_TTL = 600;
    private const DETAIL_TTL = 3600;
    private const SEARCH_TTL = 300;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly ApiEndpointRepository $endpointRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getList(string $endpoint, array $params, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildListCacheKey($endpoint, $params);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'api_list']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'api_list']);

        return null;
    }

    public function setList(string $endpoint, array $params, array $data): void
    {
        $cacheKey = $this->buildListCacheKey($endpoint, $params);

        $this->cache->set($cacheKey, $data, self::LIST_TTL);

        $this->logger->debug('Cached API list response', [
            'endpoint' => $endpoint,
            'ttl' => self::LIST_TTL,
        ]);
    }

    public function getDetail(string $endpoint, int $resourceId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildDetailCacheKey($endpoint, $resourceId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'api_detail']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'api_detail']);

        return null;
    }

    public function setDetail(string $endpoint, int $resourceId, array $data): void
    {
        $cacheKey = $this->buildDetailCacheKey($endpoint, $resourceId);

        $this->cache->set($cacheKey, $data, self::DETAIL_TTL);

        $this->logger->debug('Cached API detail response', [
            'endpoint' => $endpoint,
            'resource_id' => $resourceId,
            'ttl' => self::DETAIL_TTL,
        ]);
    }

    public function getSearch(string $endpoint, array $queryParams, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildSearchCacheKey($endpoint, $queryParams);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'api_search']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'api_search']);

        return null;
    }

    public function setSearch(string $endpoint, array $queryParams, array $data): void
    {
        $cacheKey = $this->buildSearchCacheKey($endpoint, $queryParams);

        $this->cache->set($cacheKey, $data, self::SEARCH_TTL);

        $this->logger->debug('Cached API search response', [
            'endpoint' => $endpoint,
            'ttl' => self::SEARCH_TTL,
        ]);
    }

    public function invalidateEndpoint(string $endpoint): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, $endpoint, '*');

        $this->cache->deleteByPattern($pattern);

        $this->logger->debug('Invalidated endpoint cache', [
            'endpoint' => $endpoint,
        ]);
    }

    public function invalidateResource(string $endpoint, int $resourceId): void
    {
        $detailKey = $this->buildDetailCacheKey($endpoint, $resourceId);
        $this->cache->delete($detailKey);

        $this->invalidateEndpoint($endpoint);

        $this->logger->debug('Invalidated resource cache', [
            'endpoint' => $endpoint,
            'resource_id' => $resourceId,
        ]);
    }

    public function invalidateByTags(string $endpoint, array $tags): void
    {
        foreach ($tags as $tag) {
            $tagKey = $this->keyBuilder->build('tag', $tag, 'resources');
            $resourceIds = $this->cache->get($tagKey) ?? [];

            foreach ($resourceIds as $resourceId) {
                $this->invalidateResource($endpoint, $resourceId);
            }
        }

        $this->logger->debug('Invalidated cache by tags', [
            'endpoint' => $endpoint,
            'tag_count' => count($tags),
        ]);
    }

    public function refreshResource(string $endpoint, int $resourceId): void
    {
        $detailKey = $this->buildDetailCacheKey($endpoint, $resourceId);
        $this->cache->delete($detailKey);

        $this->invalidateEndpoint($endpoint);

        $this->logger->debug('Refreshed resource cache', [
            'endpoint' => $endpoint,
            'resource_id' => $resourceId,
        ]);
    }

    public function warmEndpoint(string $endpoint): void
    {
        $endpointConfig = $this->endpointRepository->findByPath($endpoint);

        if ($endpointConfig === null || !$endpointConfig->isCacheWarmEnabled()) {
            return;
        }

        $popularResources = $this->fetchPopularResources($endpoint, 100);

        foreach ($popularResources as $resource) {
            $this->setDetail($endpoint, $resource['id'], $resource['data']);
        }

        $this->logger->debug('Warmed endpoint cache', [
            'endpoint' => $endpoint,
            'resources_warmed' => count($popularResources),
        ]);
    }

    public function handleResourceCreated(string $endpoint, int $resourceId): void
    {
        $this->invalidateEndpoint($endpoint);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'resource_created',
            'endpoint' => $endpoint,
        ]);

        $this->logger->info('Handled resource created cache invalidation', [
            'endpoint' => $endpoint,
            'resource_id' => $resourceId,
        ]);
    }

    public function handleResourceUpdated(string $endpoint, int $resourceId): void
    {
        $this->invalidateResource($endpoint, $resourceId);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'resource_updated',
            'endpoint' => $endpoint,
            'resource_id' => (string) $resourceId,
        ]);

        $this->logger->info('Handled resource updated cache invalidation', [
            'endpoint' => $endpoint,
            'resource_id' => $resourceId,
        ]);
    }

    public function handleResourceDeleted(string $endpoint, int $resourceId): void
    {
        $this->invalidateResource($endpoint, $resourceId);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'resource_deleted',
            'endpoint' => $endpoint,
            'resource_id' => (string) $resourceId,
        ]);

        $this->logger->info('Handled resource deleted cache invalidation', [
            'endpoint' => $endpoint,
            'resource_id' => $resourceId,
        ]);
    }

    public function handleBulkOperation(string $endpoint, array $resourceIds): void
    {
        foreach ($resourceIds as $resourceId) {
            $this->invalidateResource($endpoint, $resourceId);
        }

        $this->logger->info('Handled bulk operation cache invalidation', [
            'endpoint' => $endpoint,
            'resource_count' => count($resourceIds),
        ]);
    }

    public function setWithStale(string $endpoint, int $resourceId, array $data): void
    {
        $cacheKey = $this->buildDetailCacheKey($endpoint, $resourceId);
        $staleKey = $cacheKey . ':stale';

        $this->cache->set($staleKey, $data, self::DETAIL_TTL + self::STALE_TTL);
        $this->cache->set($cacheKey, $data, self::DETAIL_TTL);

        $this->logger->debug('Set API response with stale backup', [
            'endpoint' => $endpoint,
            'resource_id' => $resourceId,
        ]);
    }

    public function getOrSetList(string $endpoint, array $params, callable $fetcher): array
    {
        $cached = $this->getList($endpoint, $params);

        if ($cached !== null) {
            return $cached;
        }

        $data = $fetcher($endpoint, $params);

        if ($data !== null) {
            $this->setList($endpoint, $params, $data);
        }

        return $data;
    }

    public function getOrSetDetail(string $endpoint, int $resourceId, callable $fetcher): array
    {
        $cached = $this->getDetail($endpoint, $resourceId);

        if ($cached !== null) {
            return $cached;
        }

        $data = $fetcher($endpoint, $resourceId);

        if ($data !== null) {
            $this->setDetail($endpoint, $resourceId, $data);
        }

        return $data;
    }

    private function buildListCacheKey(string $endpoint, array $params): string
    {
        ksort($params);
        $serializedParams = json_encode($params);

        return $this->keyBuilder->build(
            self::CACHE_PREFIX,
            $endpoint,
            'list',
            md5($serializedParams)
        );
    }

    private function buildDetailCacheKey(string $endpoint, int $resourceId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, $endpoint, 'detail', (string) $resourceId);
    }

    private function buildSearchCacheKey(string $endpoint, array $queryParams): string
    {
        ksort($queryParams);
        $serializedParams = json_encode($queryParams);

        return $this->keyBuilder->build(
            self::CACHE_PREFIX,
            $endpoint,
            'search',
            md5($serializedParams)
        );
    }

    private function fetchPopularResources(string $endpoint, int $limit): array
    {
        return [];
    }
}
