<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\SearchIndexRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class SearchIndexCacheHandler
{
    private const CACHE_PREFIX = 'search_index';
    private const DEFAULT_TTL = 1800;
    private const STALE_TTL = 600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly SearchIndexRepository $searchIndexRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getProductSearchIndex(int $productId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildProductSearchIndexCacheKey($productId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'product_search_index']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'product_search_index']);

        $index = $this->searchIndexRepository->findProductIndex($productId);

        if ($index === null) {
            return null;
        }

        $data = $this->serializeSearchIndex($index);
        $this->setProductSearchIndex($productId, $data);

        return $data;
    }

    public function setProductSearchIndex(int $productId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildProductSearchIndexCacheKey($productId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached product search index', [
            'product_id' => $productId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateProductSearchIndex(int $productId): void
    {
        $cacheKey = $this->buildProductSearchIndexCacheKey($productId);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated product search index cache', [
            'product_id' => $productId,
        ]);
    }

    public function refreshProductSearchIndex(int $productId): void
    {
        $index = $this->searchIndexRepository->findProductIndex($productId);

        if ($index === null) {
            $this->cache->delete($this->buildProductSearchIndexCacheKey($productId));
            return;
        }

        $data = $this->serializeSearchIndex($index);
        $this->setProductSearchIndex($productId, $data);

        $this->logger->debug('Refreshed product search index cache', [
            'product_id' => $productId,
        ]);
    }

    public function warmProductSearchIndex(int $categoryId): void
    {
        $products = $this->searchIndexRepository->findProductIndexesByCategory($categoryId);

        foreach ($products as $product) {
            $data = $this->serializeSearchIndex($product);
            $this->setProductSearchIndex($product->getProductId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed product search index cache', [
            'category_id' => $categoryId,
            'products_warmed' => count($products),
        ]);
    }

    public function getCategorySearchIndex(int $categoryId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildCategorySearchIndexCacheKey($categoryId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'category_search_index']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'category_search_index']);

        $index = $this->searchIndexRepository->findCategoryIndex($categoryId);

        if ($index === null) {
            return null;
        }

        $data = $this->serializeSearchIndex($index);
        $this->setCategorySearchIndex($categoryId, $data);

        return $data;
    }

    public function setCategorySearchIndex(int $categoryId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildCategorySearchIndexCacheKey($categoryId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached category search index', [
            'category_id' => $categoryId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateCategorySearchIndex(int $categoryId): void
    {
        $cacheKey = $this->buildCategorySearchIndexCacheKey($categoryId);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated category search index cache', [
            'category_id' => $categoryId,
        ]);
    }

    public function refreshCategorySearchIndex(int $categoryId): void
    {
        $index = $this->searchIndexRepository->findCategoryIndex($categoryId);

        if ($index === null) {
            $this->cache->delete($this->buildCategorySearchIndexCacheKey($categoryId));
            return;
        }

        $data = $this->serializeSearchIndex($index);
        $this->setCategorySearchIndex($categoryId, $data);

        $this->logger->debug('Refreshed category search index cache', [
            'category_id' => $categoryId,
        ]);
    }

    public function getSearchSuggestions(string $query, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildSearchSuggestionsCacheKey($query);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'search_suggestions']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'search_suggestions']);

        $suggestions = $this->searchIndexRepository->findSuggestions($query);

        if ($suggestions === null) {
            return null;
        }

        $data = $this->serializeSuggestions($suggestions);
        $this->setSearchSuggestions($query, $data);

        return $data;
    }

    public function setSearchSuggestions(string $query, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildSearchSuggestionsCacheKey($query);
        $ttl = $ttl ?? 300;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached search suggestions', [
            'query' => $query,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateSearchSuggestions(string $query): void
    {
        $cacheKey = $this->buildSearchSuggestionsCacheKey($query);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated search suggestions cache', [
            'query' => $query,
        ]);
    }

    public function refreshSearchSuggestions(string $query): void
    {
        $suggestions = $this->searchIndexRepository->findSuggestions($query);

        if ($suggestions === null) {
            $this->cache->delete($this->buildSearchSuggestionsCacheKey($query));
            return;
        }

        $data = $this->serializeSuggestions($suggestions);
        $this->setSearchSuggestions($query, $data);

        $this->logger->debug('Refreshed search suggestions cache', [
            'query' => $query,
        ]);
    }

    public function handleProductIndexChange(int $productId): void
    {
        $this->invalidateProductSearchIndex($productId);

        $categoryId = $this->searchIndexRepository->findCategoryIdForProduct($productId);
        if ($categoryId !== null) {
            $this->refreshCategorySearchIndex($categoryId);
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'product_index_change',
            'product_id' => (string) $productId,
        ]);

        $this->logger->info('Handled product index change cache invalidation', [
            'product_id' => $productId,
        ]);
    }

    public function handleBulkProductIndexChange(array $productIds): void
    {
        foreach ($productIds as $productId) {
            $this->invalidateProductSearchIndex($productId);
        }

        $categoryIds = $this->searchIndexRepository->findCategoryIdsForProducts($productIds);
        foreach ($categoryIds as $categoryId) {
            $this->refreshCategorySearchIndex($categoryId);
        }

        $this->logger->info('Handled bulk product index change cache invalidation', [
            'product_count' => count($productIds),
        ]);
    }

    public function handleCategoryReindex(int $categoryId): void
    {
        $this->invalidateCategorySearchIndex($categoryId);

        $subcategoryIds = $this->searchIndexRepository->findSubcategoryIds($categoryId);
        foreach ($subcategoryIds as $subcategoryId) {
            $this->invalidateCategorySearchIndex($subcategoryId);
        }

        $productIds = $this->searchIndexRepository->findProductIdsByCategory($categoryId);
        foreach ($productIds as $productId) {
            $this->refreshProductSearchIndex($productId);
        }

        $this->logger->info('Handled category reindex cache invalidation', [
            'category_id' => $categoryId,
        ]);
    }

    public function handleSearchRankingUpdate(): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'suggestions', '*');
        $this->cache->deleteByPattern($pattern);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'search_ranking_update',
        ]);

        $this->logger->info('Handled search ranking update cache invalidation');
    }

    private function buildProductSearchIndexCacheKey(int $productId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'product', (string) $productId);
    }

    private function buildCategorySearchIndexCacheKey(int $categoryId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'category', (string) $categoryId);
    }

    private function buildSearchSuggestionsCacheKey(string $query): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'suggestions', md5($query));
    }

    private function serializeSearchIndex(object $index): array
    {
        return [
            'product_id' => $index->getProductId(),
            'name' => $index->getName(),
            'description' => $index->getDescription(),
            'keywords' => $index->getKeywords(),
            'category_path' => $index->getCategoryPath(),
            'attributes' => $index->getAttributes(),
        ];
    }

    private function serializeSuggestions(array $suggestions): array
    {
        $result = [];
        foreach ($suggestions as $suggestion) {
            $result[] = [
                'text' => $suggestion->getText(),
                'score' => $suggestion->getScore(),
                'type' => $suggestion->getType(),
            ];
        }
        return $result;
    }
}
