<?php
declare(strict_types=1);

namespace ProductService\Caching;

use Psr\Log\LoggerInterface;

final class ProductCacheManager
{
    private const CACHE_PREFIX = 'product:';
    private const DEFAULT_TTL_SECONDS = 3600;
    private const DETAILS_TTL_SECONDS = 1800;
    private const INVENTORY_TTL_SECONDS = 900;
    private const PRICING_TTL_SECONDS = 7200;

    private const LIST_CACHE_PREFIX = 'product_list:';
    private const LIST_TTL_SECONDS = 600;

    private const LOCK_PREFIX = 'product_lock:';
    private const LOCK_TTL_SECONDS = 30;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function getProduct(string $productId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $productId;
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            $this->logger->debug('Product cache hit', ['product_id' => $productId]);
            return unserialize($cached);
        }

        $this->logger->debug('Product cache miss', ['product_id' => $productId]);
        return null;
    }

    public function setProduct(string $productId, array $productData, ?int $ttl = null): void
    {
        $cacheKey = self::CACHE_PREFIX . $productId;
        $effectiveTtl = $ttl ?? self::DEFAULT_TTL_SECONDS;

        apcu_store($cacheKey, serialize($productData), $effectiveTtl);

        $this->logger->debug('Product cached', [
            'product_id' => $productId,
            'ttl' => $effectiveTtl,
        ]);
    }

    public function invalidateProduct(string $productId): void
    {
        $cacheKey = self::CACHE_PREFIX . $productId;
        apcu_delete($cacheKey);

        $this->logger->debug('Product cache invalidated', ['product_id' => $productId]);
    }

    public function getProductDetails(string $productId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $productId . ':details';
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    public function setProductDetails(string $productId, array $details): void
    {
        $cacheKey = self::CACHE_PREFIX . $productId . ':details';
        apcu_store($cacheKey, serialize($details), self::DETAILS_TTL_SECONDS);
    }

    public function invalidateProductDetails(string $productId): void
    {
        $cacheKey = self::CACHE_PREFIX . $productId . ':details';
        apcu_delete($cacheKey);
    }

    public function getProductInventory(string $productId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $productId . ':inventory';
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    public function setProductInventory(string $productId, array $inventory): void
    {
        $cacheKey = self::CACHE_PREFIX . $productId . ':inventory';
        apcu_store($cacheKey, serialize($inventory), self::INVENTORY_TTL_SECONDS);
    }

    public function invalidateProductInventory(string $productId): void
    {
        $cacheKey = self::CACHE_PREFIX . $productId . ':inventory';
        apcu_delete($cacheKey);
    }

    public function getProductPricing(string $productId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $productId . ':pricing';
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    public function setProductPricing(string $productId, array $pricing): void
    {
        $cacheKey = self::CACHE_PREFIX . $productId . ':pricing';
        apcu_store($cacheKey, serialize($pricing), self::PRICING_TTL_SECONDS);
    }

    public function invalidateProductPricing(string $productId): void
    {
        $cacheKey = self::CACHE_PREFIX . $productId . ':pricing';
        apcu_delete($cacheKey);
    }

    public function getProductList(string $listKey): ?array
    {
        $cacheKey = self::LIST_CACHE_PREFIX . $listKey;
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    public function setProductList(string $listKey, array $productIds): void
    {
        $cacheKey = self::LIST_CACHE_PREFIX . $listKey;
        apcu_store($cacheKey, serialize($productIds), self::LIST_TTL_SECONDS);
    }

    public function invalidateProductList(string $listKey): void
    {
        $cacheKey = self::LIST_CACHE_PREFIX . $listKey;
        apcu_delete($cacheKey);
    }

    public function invalidateAllProductData(string $productId): void
    {
        $this->invalidateProduct($productId);
        $this->invalidateProductDetails($productId);
        $this->invalidateProductInventory($productId);
        $this->invalidateProductPricing($productId);

        $this->logger->info('All product data invalidated', ['product_id' => $productId]);
    }

    public function acquireLock(string $productId): bool
    {
        $lockKey = self::LOCK_PREFIX . $productId;
        return apcu_add($lockKey, time(), self::LOCK_TTL_SECONDS);
    }

    public function releaseLock(string $productId): void
    {
        $lockKey = self::LOCK_PREFIX . $productId;
        apcu_delete($lockKey);
    }
}
