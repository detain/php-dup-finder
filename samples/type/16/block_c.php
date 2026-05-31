<?php
declare(strict_types=1);

namespace OrderService\Caching;

use Psr\Log\LoggerInterface;

final class OrderCacheManager
{
    private const CACHE_PREFIX = 'order:';
    private const DEFAULT_TTL_SECONDS = 3600;
    private const DETAILS_TTL_SECONDS = 1800;
    private const ITEMS_TTL_SECONDS = 900;
    private const SHIPPING_TTL_SECONDS = 7200;

    private const LIST_CACHE_PREFIX = 'order_list:';
    private const LIST_TTL_SECONDS = 600;

    private const LOCK_PREFIX = 'order_lock:';
    private const LOCK_TTL_SECONDS = 30;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function getOrder(string $orderId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $orderId;
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            $this->logger->debug('Order cache hit', ['order_id' => $orderId]);
            return unserialize($cached);
        }

        $this->logger->debug('Order cache miss', ['order_id' => $orderId]);
        return null;
    }

    public function setOrder(string $orderId, array $orderData, ?int $ttl = null): void
    {
        $cacheKey = self::CACHE_PREFIX . $orderId;
        $effectiveTtl = $ttl ?? self::DEFAULT_TTL_SECONDS;

        apcu_store($cacheKey, serialize($orderData), $effectiveTtl);

        $this->logger->debug('Order cached', [
            'order_id' => $orderId,
            'ttl' => $effectiveTtl,
        ]);
    }

    public function invalidateOrder(string $orderId): void
    {
        $cacheKey = self::CACHE_PREFIX . $orderId;
        apcu_delete($cacheKey);

        $this->logger->debug('Order cache invalidated', ['order_id' => $orderId]);
    }

    public function getOrderDetails(string $orderId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $orderId . ':details';
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    public function setOrderDetails(string $orderId, array $details): void
    {
        $cacheKey = self::CACHE_PREFIX . $orderId . ':details';
        apcu_store($cacheKey, serialize($details), self::DETAILS_TTL_SECONDS);
    }

    public function invalidateOrderDetails(string $orderId): void
    {
        $cacheKey = self::CACHE_PREFIX . $orderId . ':details';
        apcu_delete($cacheKey);
    }

    public function getOrderItems(string $orderId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $orderId . ':items';
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    public function setOrderItems(string $orderId, array $items): void
    {
        $cacheKey = self::CACHE_PREFIX . $orderId . ':items';
        apcu_store($cacheKey, serialize($items), self::ITEMS_TTL_SECONDS);
    }

    public function invalidateOrderItems(string $orderId): void
    {
        $cacheKey = self::CACHE_PREFIX . $orderId . ':items';
        apcu_delete($cacheKey);
    }

    public function getOrderShipping(string $orderId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $orderId . ':shipping';
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    public function setOrderShipping(string $orderId, array $shipping): void
    {
        $cacheKey = self::CACHE_PREFIX . $orderId . ':shipping';
        apcu_store($cacheKey, serialize($shipping), self::SHIPPING_TTL_SECONDS);
    }

    public function invalidateOrderShipping(string $orderId): void
    {
        $cacheKey = self::CACHE_PREFIX . $orderId . ':shipping';
        apcu_delete($cacheKey);
    }

    public function getOrderList(string $listKey): ?array
    {
        $cacheKey = self::LIST_CACHE_PREFIX . $listKey;
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    public function setOrderList(string $listKey, array $orderIds): void
    {
        $cacheKey = self::LIST_CACHE_PREFIX . $listKey;
        apcu_store($cacheKey, serialize($orderIds), self::LIST_TTL_SECONDS);
    }

    public function invalidateOrderList(string $listKey): void
    {
        $cacheKey = self::LIST_CACHE_PREFIX . $listKey;
        apcu_delete($cacheKey);
    }

    public function invalidateAllOrderData(string $orderId): void
    {
        $this->invalidateOrder($orderId);
        $this->invalidateOrderDetails($orderId);
        $this->invalidateOrderItems($orderId);
        $this->invalidateOrderShipping($orderId);

        $this->logger->info('All order data invalidated', ['order_id' => $orderId]);
    }

    public function acquireLock(string $orderId): bool
    {
        $lockKey = self::LOCK_PREFIX . $orderId;
        return apcu_add($lockKey, time(), self::LOCK_TTL_SECONDS);
    }

    public function releaseLock(string $orderId): void
    {
        $lockKey = self::LOCK_PREFIX . $orderId;
        apcu_delete($lockKey);
    }
}
