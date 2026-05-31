<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\OrderRepository;
use App\Repository\CustomerRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class OrderCacheHandler
{
    private const CACHE_PREFIX = 'order';
    private const DEFAULT_TTL = 1800;
    private const STALE_TTL = 600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly OrderRepository $orderRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getOrder(int $orderId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildOrderCacheKey($orderId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'order']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'order']);

        $order = $this->orderRepository->find($orderId);
        if ($order === null) {
            return null;
        }

        $data = $this->serializeOrder($order);
        $this->setOrder($orderId, $data);
        return $data;
    }

    public function setOrder(int $orderId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildOrderCacheKey($orderId);
        $this->cache->set($cacheKey, $data, $ttl ?? self::DEFAULT_TTL);
    }

    public function invalidateOrder(int $orderId): void
    {
        $this->cache->delete($this->buildOrderCacheKey($orderId));
    }

    public function refreshOrder(int $orderId): void
    {
        $order = $this->orderRepository->find($orderId);
        if ($order === null) {
            $this->cache->delete($this->buildOrderCacheKey($orderId));
            return;
        }
        $this->setOrder($orderId, $this->serializeOrder($order));
    }

    public function warmCustomerOrders(int $customerId): void
    {
        $orders = $this->orderRepository->findRecentByCustomer($customerId, 10);
        foreach ($orders as $order) {
            $this->setOrder($order->getId(), $this->serializeOrder($order), self::DEFAULT_TTL);
        }
    }

    public function getOrderItems(int $orderId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildOrderItemsCacheKey($orderId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'order_items']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'order_items']);

        $items = $this->orderRepository->findItems($orderId);
        if ($items === null) {
            return null;
        }

        $data = $this->serializeOrderItems($items);
        $this->setOrderItems($orderId, $data);
        return $data;
    }

    public function setOrderItems(int $orderId, array $data, ?int $ttl = null): void
    {
        $this->cache->set($this->buildOrderItemsCacheKey($orderId), $data, $ttl ?? self::DEFAULT_TTL);
    }

    public function invalidateOrderItems(int $orderId): void
    {
        $this->cache->delete($this->buildOrderItemsCacheKey($orderId));
    }

    public function refreshOrderItems(int $orderId): void
    {
        $items = $this->orderRepository->findItems($orderId);
        if ($items === null) {
            $this->cache->delete($this->buildOrderItemsCacheKey($orderId));
            return;
        }
        $this->setOrderItems($orderId, $this->serializeOrderItems($items));
    }

    public function getOrderStatus(int $orderId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildOrderStatusCacheKey($orderId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'order_status']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'order_status']);

        $status = $this->orderRepository->findStatus($orderId);
        if ($status === null) {
            return null;
        }

        $data = $this->serializeOrderStatus($status);
        $this->setOrderStatus($orderId, $data);
        return $data;
    }

    public function setOrderStatus(int $orderId, array $data, ?int $ttl = null): void
    {
        $this->cache->set($this->buildOrderStatusCacheKey($orderId), $data, $ttl ?? 300);
    }

    public function invalidateOrderStatus(int $orderId): void
    {
        $this->cache->delete($this->buildOrderStatusCacheKey($orderId));
    }

    public function refreshOrderStatus(int $orderId): void
    {
        $status = $this->orderRepository->findStatus($orderId);
        if ($status === null) {
            $this->cache->delete($this->buildOrderStatusCacheKey($orderId));
            return;
        }
        $this->setOrderStatus($orderId, $this->serializeOrderStatus($status));
    }

    public function getCustomerOrderSummary(int $customerId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildCustomerOrderSummaryCacheKey($customerId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'customer_order_summary']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'customer_order_summary']);

        $summary = $this->customerRepository->getOrderSummary($customerId);
        if ($summary === null) {
            return null;
        }

        $data = $this->serializeCustomerOrderSummary($summary);
        $this->setCustomerOrderSummary($customerId, $data);
        return $data;
    }

    public function setCustomerOrderSummary(int $customerId, array $data, ?int $ttl = null): void
    {
        $this->cache->set($this->buildCustomerOrderSummaryCacheKey($customerId), $data, $ttl ?? self::DEFAULT_TTL);
    }

    public function invalidateCustomerOrderSummary(int $customerId): void
    {
        $this->cache->delete($this->buildCustomerOrderSummaryCacheKey($customerId));
    }

    public function refreshCustomerOrderSummary(int $customerId): void
    {
        $summary = $this->customerRepository->getOrderSummary($customerId);
        if ($summary === null) {
            $this->cache->delete($this->buildCustomerOrderSummaryCacheKey($customerId));
            return;
        }
        $this->setCustomerOrderSummary($customerId, $this->serializeCustomerOrderSummary($summary));
    }

    public function handleOrderUpdate(int $orderId): void
    {
        $this->invalidateOrder($orderId);
        $this->invalidateOrderItems($orderId);
        $this->invalidateOrderStatus($orderId);

        $customerId = $this->orderRepository->find($orderId)?->getCustomerId();
        if ($customerId !== null) {
            $this->invalidateCustomerOrderSummary($customerId);
        }

        $this->logger->info('Handled order update cache invalidation', ['order_id' => $orderId]);
    }

    public function handleOrderStatusChange(int $orderId): void
    {
        $this->invalidateOrderStatus($orderId);
        $customerId = $this->orderRepository->find($orderId)?->getCustomerId();
        if ($customerId !== null) {
            $this->invalidateCustomerOrderSummary($customerId);
        }
        $this->logger->info('Handled order status change cache invalidation', ['order_id' => $orderId]);
    }

    private function buildOrderCacheKey(int $orderId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'order', (string) $orderId);
    }

    private function buildOrderItemsCacheKey(int $orderId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'order', (string) $orderId, 'items');
    }

    private function buildOrderStatusCacheKey(int $orderId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'order', (string) $orderId, 'status');
    }

    private function buildCustomerOrderSummaryCacheKey(int $customerId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'customer', (string) $customerId, 'order_summary');
    }

    private function serializeOrder(object $order): array
    {
        return [
            'id' => $order->getId(),
            'customer_id' => $order->getCustomerId(),
            'status' => $order->getStatus(),
            'total' => $order->getTotal(),
            'items_count' => $order->getItemsCount(),
        ];
    }

    private function serializeOrderItems(array $items): array
    {
        return array_map(fn($item) => [
            'product_id' => $item->getProductId(),
            'quantity' => $item->getQuantity(),
            'price' => $item->getPrice(),
        ], $items);
    }

    private function serializeOrderStatus(object $status): array
    {
        return [
            'status' => $status->getStatus(),
            'updated_at' => $status->getUpdatedAt()?->format(\DATE_ATOM),
            'tracking_number' => $status->getTrackingNumber(),
        ];
    }

    private function serializeCustomerOrderSummary(array $summary): array
    {
        return [
            'total_orders' => $summary['total_orders'],
            'total_spent' => $summary['total_spent'],
            'avg_order_value' => $summary['avg_order_value'],
        ];
    }
}
