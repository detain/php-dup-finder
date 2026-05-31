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
    private const STALE_TTL = 300;

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
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached order', [
            'order_id' => $orderId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateOrder(int $orderId): void
    {
        $cacheKey = $this->buildOrderCacheKey($orderId);

        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated order cache', [
            'order_id' => $orderId,
        ]);
    }

    public function invalidateCustomerOrders(int $customerId): void
    {
        $orders = $this->orderRepository->findByCustomerId($customerId);

        $cacheKeys = array_map(
            fn($order) => $this->buildOrderCacheKey($order->getId()),
            $orders
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateCustomerOrderSummary($customerId);

        $this->logger->info('Invalidated orders for customer', [
            'customer_id' => $customerId,
            'order_count' => count($orders),
        ]);
    }

    public function refreshOrder(int $orderId): void
    {
        $cacheKey = $this->buildOrderCacheKey($orderId);

        $order = $this->orderRepository->find($orderId);

        if ($order === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeOrder($order);
        $this->setOrder($orderId, $data);

        $this->logger->debug('Refreshed order cache', [
            'order_id' => $orderId,
        ]);
    }

    public function warmCustomer(int $customerId): void
    {
        $orders = $this->orderRepository->findRecentByCustomerId($customerId, 50);

        foreach ($orders as $order) {
            $data = $this->serializeOrder($order);
            $this->setOrder($order->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed order cache for customer', [
            'customer_id' => $customerId,
            'orders_warmed' => count($orders),
        ]);
    }

    public function handleOrderStatusChange(int $orderId): void
    {
        $this->invalidateOrder($orderId);

        $statusKeys = [
            $this->keyBuilder->build('order', $orderId, 'status_history'),
            $this->keyBuilder->build('order', $orderId, 'tracking'),
            $this->keyBuilder->build('order', $orderId, 'fulfillment'),
        ];

        foreach ($statusKeys as $key) {
            $this->cache->delete($key);
        }

        $order = $this->orderRepository->find($orderId);
        if ($order !== null) {
            $this->invalidateCustomerOrders($order->getCustomerId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'order_status_change',
            'order_id' => (string) $orderId,
        ]);

        $this->logger->info('Handled order status change cache invalidation', [
            'order_id' => $orderId,
        ]);
    }

    public function handlePaymentReceived(int $orderId): void
    {
        $this->invalidateOrder($orderId);

        $paymentKeys = [
            $this->keyBuilder->build('order', $orderId, 'payment_status'),
            $this->keyBuilder->build('order', $orderId, 'invoice'),
            $this->keyBuilder->build('order', $orderId, 'receipt'),
        ];

        foreach ($paymentKeys as $key) {
            $this->cache->delete($key);
        }

        $order = $this->orderRepository->find($orderId);
        if ($order !== null) {
            $this->invalidateCustomerOrders($order->getCustomerId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'payment_received',
            'order_id' => (string) $orderId,
        ]);

        $this->logger->info('Handled payment received cache invalidation', [
            'order_id' => $orderId,
        ]);
    }

    public function handleShippingUpdate(int $orderId): void
    {
        $this->invalidateOrder($orderId);

        $shippingKeys = [
            $this->keyBuilder->build('order', $orderId, 'tracking'),
            $this->keyBuilder->build('order', $orderId, 'delivery_estimate'),
            $this->keyBuilder->build('order', $orderId, 'shipping_label'),
        ];

        foreach ($shippingKeys as $key) {
            $this->cache->delete($key);
        }

        $this->logger->info('Handled shipping update cache invalidation', [
            'order_id' => $orderId,
        ]);
    }

    public function handleOrderCancellation(int $orderId): void
    {
        $this->invalidateOrder($orderId);

        $cancellationKeys = [
            $this->keyBuilder->build('order', $orderId, 'refund_status'),
            $this->keyBuilder->build('order', $orderId, 'cancellation_reason'),
            $this->keyBuilder->build('order', $orderId, 'restocking_info'),
        ];

        foreach ($cancellationKeys as $key) {
            $this->cache->delete($key);
        }

        $order = $this->orderRepository->find($orderId);
        if ($order !== null) {
            $this->invalidateCustomerOrders($order->getCustomerId());
        }

        $this->logger->info('Handled order cancellation cache invalidation', [
            'order_id' => $orderId,
        ]);
    }

    public function setWithStale(int $orderId, array $data): void
    {
        $cacheKey = $this->buildOrderCacheKey($orderId);
        $staleKey = $cacheKey . ':stale';

        $this->cache->set($staleKey, $data, self::DEFAULT_TTL + self::STALE_TTL);
        $this->cache->set($cacheKey, $data, self::DEFAULT_TTL);

        $this->logger->debug('Set order with stale backup', [
            'order_id' => $orderId,
        ]);
    }

    public function getOrSet(int $orderId, callable $fetcher, ?int $ttl = null): array
    {
        $cached = $this->getOrder($orderId);

        if ($cached !== null) {
            return $cached;
        }

        $data = $fetcher($orderId);

        if ($data !== null) {
            $this->setOrder($orderId, $data, $ttl);
        }

        return $data;
    }

    private function buildOrderCacheKey(int $orderId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'order', $orderId);
    }

    private function buildCustomerOrderSummaryCacheKey(int $customerId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'customer', $customerId, 'order_summary');
    }

    private function invalidateCustomerOrderSummary(int $customerId): void
    {
        $summaryKey = $this->buildCustomerOrderSummaryCacheKey($customerId);
        $this->cache->delete($summaryKey);
    }

    private function serializeOrder(object $order): array
    {
        return [
            'id' => $order->getId(),
            'customer_id' => $order->getCustomerId(),
            'order_number' => $order->getOrderNumber(),
            'status' => $order->getStatus(),
            'total' => $order->getTotal(),
            'currency' => $order->getCurrency(),
            'created_at' => $order->getCreatedAt()?->format(\DATE_ATOM),
            'updated_at' => $order->getUpdatedAt()?->format(\DATE_ATOM),
        ];
    }
}
