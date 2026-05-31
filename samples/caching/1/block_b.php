<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\ProductRepository;
use App\Repository\CategoryRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ProductCatalogCacheHandler
{
    private const CACHE_PREFIX = 'product_catalog';
    private const DEFAULT_TTL = 7200;
    private const STALE_TTL = 600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly ProductRepository $productRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getProduct(int $productId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildProductCacheKey($productId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'product']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'product']);

        $product = $this->productRepository->find($productId);

        if ($product === null) {
            return null;
        }

        $data = $this->serializeProduct($product);
        $this->setProduct($productId, $data);

        return $data;
    }

    public function setProduct(int $productId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildProductCacheKey($productId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached product', [
            'product_id' => $productId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateProduct(int $productId): void
    {
        $cacheKey = $this->buildProductCacheKey($productId);

        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated product cache', [
            'product_id' => $productId,
        ]);
    }

    public function invalidateCategoryProducts(int $categoryId): void
    {
        $products = $this->productRepository->findByCategoryId($categoryId);

        $cacheKeys = array_map(
            fn($product) => $this->buildProductCacheKey($product->getId()),
            $products
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateCategory($categoryId);

        $this->logger->info('Invalidated products in category', [
            'category_id' => $categoryId,
            'product_count' => count($products),
        ]);
    }

    public function refreshProduct(int $productId): void
    {
        $product = $this->productRepository->find($productId);

        if ($product === null) {
            $this->cache->delete($this->buildProductCacheKey($productId));
            return;
        }

        $data = $this->serializeProduct($product);
        $this->setProduct($productId, $data);

        $this->logger->debug('Refreshed product cache', [
            'product_id' => $productId,
        ]);
    }

    public function warmCategory(int $categoryId): void
    {
        $products = $this->productRepository->findByCategoryId($categoryId);

        foreach ($products as $product) {
            $data = $this->serializeProduct($product);
            $this->setProduct($product->getId(), $data, self::DEFAULT_TTL);
        }

        $categoryData = $this->serializeCategory(
            $this->categoryRepository->find($categoryId)
        );
        $this->setCategory($categoryId, $categoryData);

        $this->logger->debug('Warmed category cache', [
            'category_id' => $categoryId,
            'products_warmed' => count($products),
        ]);
    }

    public function handlePriceChange(int $productId): void
    {
        $this->invalidateProduct($productId);

        $relatedKeys = [
            $this->keyBuilder->build('product', $productId, 'pricing'),
            $this->keyBuilder->build('product', $productId, 'availability'),
            $this->keyBuilder->build('product', $productId, 'promotions'),
        ];

        foreach ($relatedKeys as $key) {
            $this->cache->delete($key);
        }

        $categoryId = $this->productRepository->find($productId)?->getCategoryId();
        if ($categoryId !== null) {
            $this->invalidateCategoryPriceSummary($categoryId);
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'price_change',
            'product_id' => (string) $productId,
        ]);

        $this->logger->info('Handled price change cache invalidation', [
            'product_id' => $productId,
        ]);
    }

    public function handleInventoryChange(int $productId): void
    {
        $this->invalidateProduct($productId);

        $inventoryKeys = [
            $this->keyBuilder->build('product', $productId, 'stock_level'),
            $this->keyBuilder->build('product', $productId, 'availability'),
            $this->keyBuilder->build('product', $productId, 'waitlist'),
        ];

        foreach ($inventoryKeys as $key) {
            $this->cache->delete($key);
        }

        $categoryId = $this->productRepository->find($productId)?->getCategoryId();
        if ($categoryId !== null) {
            $this->invalidateCategoryAvailabilitySummary($categoryId);
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'inventory_change',
            'product_id' => (string) $productId,
        ]);

        $this->logger->info('Handled inventory change cache invalidation', [
            'product_id' => $productId,
        ]);
    }

    public function handleCategoryChange(int $categoryId): void
    {
        $this->invalidateCategory($categoryId);

        $categoryKeys = [
            $this->keyBuilder->build('category', $categoryId, 'products'),
            $this->keyBuilder->build('category', $categoryId, 'subcategories'),
            $this->keyBuilder->build('category', $categoryId, 'breadcrumbs'),
        ];

        foreach ($categoryKeys as $key) {
            $this->cache->delete($key);
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'category_change',
            'category_id' => (string) $categoryId,
        ]);

        $this->logger->info('Handled category change cache invalidation', [
            'category_id' => $categoryId,
        ]);
    }

    public function handleProductActivation(int $productId): void
    {
        $this->invalidateProduct($productId);

        $activationKeys = [
            $this->keyBuilder->build('product', $productId, 'status'),
            $this->keyBuilder->build('product', $productId, 'visibility'),
        ];

        foreach ($activationKeys as $key) {
            $this->cache->delete($key);
        }

        $product = $this->productRepository->find($productId);
        if ($product !== null) {
            $this->invalidateCategoryProducts($product->getCategoryId());
        }

        $this->logger->info('Handled product activation cache invalidation', [
            'product_id' => $productId,
        ]);
    }

    public function getCategory(int $categoryId): ?array
    {
        $cacheKey = $this->buildCategoryCacheKey($categoryId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'category']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'category']);

        $category = $this->categoryRepository->find($categoryId);

        if ($category === null) {
            return null;
        }

        $data = $this->serializeCategory($category);
        $this->setCategory($categoryId, $data);

        return $data;
    }

    public function setCategory(int $categoryId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildCategoryCacheKey($categoryId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidateCategory(int $categoryId): void
    {
        $this->cache->delete($this->buildCategoryCacheKey($categoryId));
    }

    public function invalidateCategoryPriceSummary(int $categoryId): void
    {
        $priceSummaryKey = $this->keyBuilder->build('category', $categoryId, 'price_summary');
        $this->cache->delete($priceSummaryKey);
    }

    public function invalidateCategoryAvailabilitySummary(int $categoryId): void
    {
        $availabilityKey = $this->keyBuilder->build('category', $categoryId, 'availability_summary');
        $this->cache->delete($availabilityKey);
    }

    public function setWithStale(int $productId, array $data): void
    {
        $cacheKey = $this->buildProductCacheKey($productId);
        $staleKey = $cacheKey . ':stale';

        $this->cache->set($staleKey, $data, self::DEFAULT_TTL + self::STALE_TTL);
        $this->cache->set($cacheKey, $data, self::DEFAULT_TTL);

        $this->logger->debug('Set product with stale backup', [
            'product_id' => $productId,
        ]);
    }

    public function getOrSet(int $productId, callable $fetcher, ?int $ttl = null): array
    {
        $cached = $this->getProduct($productId);

        if ($cached !== null) {
            return $cached;
        }

        $data = $fetcher($productId);

        if ($data !== null) {
            $this->setProduct($productId, $data, $ttl);
        }

        return $data;
    }

    private function buildProductCacheKey(int $productId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'product', $productId);
    }

    private function buildCategoryCacheKey(int $categoryId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'category', $categoryId);
    }

    private function serializeProduct(object $product): array
    {
        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'price' => $product->getPrice(),
            'category_id' => $product->getCategoryId(),
            'status' => $product->getStatus(),
            'stock_quantity' => $product->getStockQuantity(),
            'created_at' => $product->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }

    private function serializeCategory(object $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'slug' => $category->getSlug(),
            'parent_id' => $category->getParentId(),
            'product_count' => $category->getProductCount(),
        ];
    }
}
