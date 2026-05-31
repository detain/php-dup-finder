<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\ShipmentRepository;
use App\Repository\OrderRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ShipmentCacheHandler
{
    private const CACHE_PREFIX = 'shipment';
    private const DEFAULT_TTL = 7200;
    private const STALE_TTL = 600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly ShipmentRepository $shipmentRepository,
        private readonly OrderRepository $orderRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getShipment(int $shipmentId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildShipmentCacheKey($shipmentId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'shipment']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'shipment']);

        $shipment = $this->shipmentRepository->find($shipmentId);

        if ($shipment === null) {
            return null;
        }

        $data = $this->serializeShipment($shipment);
        $this->setShipment($shipmentId, $data);

        return $data;
    }

    public function setShipment(int $shipmentId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildShipmentCacheKey($shipmentId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached shipment', [
            'shipment_id' => $shipmentId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateShipment(int $shipmentId): void
    {
        $cacheKey = $this->buildShipmentCacheKey($shipmentId);

        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated shipment cache', [
            'shipment_id' => $shipmentId,
        ]);
    }

    public function invalidateOrderShipments(int $orderId): void
    {
        $shipments = $this->shipmentRepository->findByOrderId($orderId);

        $cacheKeys = array_map(
            fn($shipment) => $this->buildShipmentCacheKey($shipment->getId()),
            $shipments
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateOrderShipmentSummary($orderId);

        $this->logger->info('Invalidated shipments for order', [
            'order_id' => $orderId,
            'shipment_count' => count($shipments),
        ]);
    }

    public function refreshShipment(int $shipmentId): void
    {
        $cacheKey = $this->buildShipmentCacheKey($shipmentId);

        $shipment = $this->shipmentRepository->find($shipmentId);

        if ($shipment === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeShipment($shipment);
        $this->setShipment($shipmentId, $data);

        $this->logger->debug('Refreshed shipment cache', [
            'shipment_id' => $shipmentId,
        ]);
    }

    public function warmOrder(int $orderId): void
    {
        $shipments = $this->shipmentRepository->findByOrderId($orderId);

        foreach ($shipments as $shipment) {
            $data = $this->serializeShipment($shipment);
            $this->setShipment($shipment->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed shipment cache for order', [
            'order_id' => $orderId,
            'shipments_warmed' => count($shipments),
        ]);
    }

    public function handleShippingLabelGenerated(int $shipmentId): void
    {
        $this->invalidateShipment($shipmentId);

        $labelKeys = [
            $this->keyBuilder->build('shipment', $shipmentId, 'label_url'),
            $this->keyBuilder->build('shipment', $shipmentId, 'tracking_number'),
            $this->keyBuilder->build('shipment', $shipmentId, 'carrier_info'),
        ];

        foreach ($labelKeys as $key) {
            $this->cache->delete($key);
        }

        $shipment = $this->shipmentRepository->find($shipmentId);
        if ($shipment !== null) {
            $this->invalidateOrderShipments($shipment->getOrderId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'shipping_label_generated',
            'shipment_id' => (string) $shipmentId,
        ]);

        $this->logger->info('Handled shipping label generated cache invalidation', [
            'shipment_id' => $shipmentId,
        ]);
    }

    public function handleTrackingUpdate(int $shipmentId): void
    {
        $this->invalidateShipment($shipmentId);

        $trackingKeys = [
            $this->keyBuilder->build('shipment', $shipmentId, 'tracking_events'),
            $this->keyBuilder->build('shipment', $shipmentId, 'current_location'),
            $this->keyBuilder->build('shipment', $shipmentId, 'estimated_delivery'),
        ];

        foreach ($trackingKeys as $key) {
            $this->cache->delete($key);
        }

        $shipment = $this->shipmentRepository->find($shipmentId);
        if ($shipment !== null) {
            $this->invalidateOrderShipments($shipment->getOrderId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'tracking_update',
            'shipment_id' => (string) $shipmentId,
        ]);

        $this->logger->info('Handled tracking update cache invalidation', [
            'shipment_id' => $shipmentId,
        ]);
    }

    public function handleDeliveredShipment(int $shipmentId): void
    {
        $this->invalidateShipment($shipmentId);

        $deliveredKeys = [
            $this->keyBuilder->build('shipment', $shipmentId, 'delivery_confirmation'),
            $this->keyBuilder->build('shipment', $shipmentId, 'signature'),
            $this->keyBuilder->build('shipment', $shipmentId, 'delivery_photo'),
        ];

        foreach ($deliveredKeys as $key) {
            $this->cache->delete($key);
        }

        $shipment = $this->shipmentRepository->find($shipmentId);
        if ($shipment !== null) {
            $this->invalidateOrderShipments($shipment->getOrderId());
        }

        $this->logger->info('Handled delivered shipment cache invalidation', [
            'shipment_id' => $shipmentId,
        ]);
    }

    public function handleReturnInitiated(int $shipmentId): void
    {
        $this->invalidateShipment($shipmentId);

        $returnKeys = [
            $this->keyBuilder->build('shipment', $shipmentId, 'return_label'),
            $this->keyBuilder->build('shipment', $shipmentId, 'return_tracking'),
            $this->keyBuilder->build('shipment', $shipmentId, 'refund_status'),
        ];

        foreach ($returnKeys as $key) {
            $this->cache->delete($key);
        }

        $shipment = $this->shipmentRepository->find($shipmentId);
        if ($shipment !== null) {
            $this->invalidateOrderShipments($shipment->getOrderId());
        }

        $this->logger->info('Handled return initiated cache invalidation', [
            'shipment_id' => $shipmentId,
        ]);
    }

    public function setWithStale(int $shipmentId, array $data): void
    {
        $cacheKey = $this->buildShipmentCacheKey($shipmentId);
        $staleKey = $cacheKey . ':stale';

        $this->cache->set($staleKey, $data, self::DEFAULT_TTL + self::STALE_TTL);
        $this->cache->set($cacheKey, $data, self::DEFAULT_TTL);

        $this->logger->debug('Set shipment with stale backup', [
            'shipment_id' => $shipmentId,
        ]);
    }

    public function getOrSet(int $shipmentId, callable $fetcher, ?int $ttl = null): array
    {
        $cached = $this->getShipment($shipmentId);

        if ($cached !== null) {
            return $cached;
        }

        $data = $fetcher($shipmentId);

        if ($data !== null) {
            $this->setShipment($shipmentId, $data, $ttl);
        }

        return $data;
    }

    private function buildShipmentCacheKey(int $shipmentId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'shipment', $shipmentId);
    }

    private function buildOrderShipmentSummaryCacheKey(int $orderId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'order', $orderId, 'shipment_summary');
    }

    private function invalidateOrderShipmentSummary(int $orderId): void
    {
        $summaryKey = $this->buildOrderShipmentSummaryCacheKey($orderId);
        $this->cache->delete($summaryKey);
    }

    private function serializeShipment(object $shipment): array
    {
        return [
            'id' => $shipment->getId(),
            'order_id' => $shipment->getOrderId(),
            'tracking_number' => $shipment->getTrackingNumber(),
            'carrier' => $shipment->getCarrier(),
            'status' => $shipment->getStatus(),
            'estimated_delivery' => $shipment->getEstimatedDelivery()?->format(\DATE_ATOM),
            'shipped_at' => $shipment->getShippedAt()?->format(\DATE_ATOM),
            'delivered_at' => $shipment->getDeliveredAt()?->format(\DATE_ATOM),
        ];
    }
}
