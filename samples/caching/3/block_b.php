<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\ShippingRateRepository;
use App\Repository\WarehouseRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ShippingCacheHandler
{
    private const CACHE_PREFIX = 'shipping';
    private const DEFAULT_TTL = 7200;
    private const STALE_TTL = 1800;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly ShippingRateRepository $shippingRateRepository,
        private readonly WarehouseRepository $warehouseRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getShippingRates(int $originZip, int $destZip, string $countryCode, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildShippingRatesCacheKey($originZip, $destZip, $countryCode);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'shipping_rates']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'shipping_rates']);

        $rates = $this->shippingRateRepository->findRates($originZip, $destZip, $countryCode);

        if ($rates === null) {
            return null;
        }

        $data = $this->serializeShippingRates($rates);
        $this->setShippingRates($originZip, $destZip, $countryCode, $data);

        return $data;
    }

    public function setShippingRates(int $originZip, int $destZip, string $countryCode, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildShippingRatesCacheKey($originZip, $destZip, $countryCode);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached shipping rates', [
            'origin' => $originZip,
            'destination' => $destZip,
            'country' => $countryCode,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateShippingRates(int $originZip, int $destZip, string $countryCode): void
    {
        $cacheKey = $this->buildShippingRatesCacheKey($originZip, $destZip, $countryCode);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated shipping rates cache', [
            'origin' => $originZip,
            'destination' => $destZip,
        ]);
    }

    public function refreshShippingRates(int $originZip, int $destZip, string $countryCode): void
    {
        $rates = $this->shippingRateRepository->findRates($originZip, $destZip, $countryCode);

        if ($rates === null) {
            $this->cache->delete($this->buildShippingRatesCacheKey($originZip, $destZip, $countryCode));
            return;
        }

        $data = $this->serializeShippingRates($rates);
        $this->setShippingRates($originZip, $destZip, $countryCode, $data);

        $this->logger->debug('Refreshed shipping rates cache', [
            'origin' => $originZip,
            'destination' => $destZip,
        ]);
    }

    public function warmShippingRates(int $zipCode, array $carriers): void
    {
        foreach ($carriers as $carrier) {
            $rates = $this->shippingRateRepository->findRatesForCarrier($zipCode, $carrier);

            if ($rates !== null) {
                foreach ($rates as $destZip => $rateData) {
                    $data = $this->serializeShippingRates([$rateData]);
                    $this->setShippingRates($zipCode, $destZip, $rateData['country'], $data, self::DEFAULT_TTL);
                }
            }
        }

        $this->logger->debug('Warmed shipping rates cache', [
            'zip_code' => $zipCode,
            'carriers_warmed' => count($carriers),
        ]);
    }

    public function getWarehouseInventory(int $warehouseId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildWarehouseInventoryCacheKey($warehouseId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'warehouse_inventory']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'warehouse_inventory']);

        $inventory = $this->warehouseRepository->getInventory($warehouseId);

        if ($inventory === null) {
            return null;
        }

        $data = $this->serializeWarehouseInventory($inventory);
        $this->setWarehouseInventory($warehouseId, $data);

        return $data;
    }

    public function setWarehouseInventory(int $warehouseId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildWarehouseInventoryCacheKey($warehouseId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached warehouse inventory', [
            'warehouse_id' => $warehouseId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateWarehouseInventory(int $warehouseId): void
    {
        $cacheKey = $this->buildWarehouseInventoryCacheKey($warehouseId);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated warehouse inventory cache', [
            'warehouse_id' => $warehouseId,
        ]);
    }

    public function refreshWarehouseInventory(int $warehouseId): void
    {
        $inventory = $this->warehouseRepository->getInventory($warehouseId);

        if ($inventory === null) {
            $this->cache->delete($this->buildWarehouseInventoryCacheKey($warehouseId));
            return;
        }

        $data = $this->serializeWarehouseInventory($inventory);
        $this->setWarehouseInventory($warehouseId, $data);

        $this->logger->debug('Refreshed warehouse inventory cache', [
            'warehouse_id' => $warehouseId,
        ]);
    }

    public function warmWarehouseInventory(array $warehouseIds): void
    {
        foreach ($warehouseIds as $warehouseId) {
            $inventory = $this->warehouseRepository->getInventory($warehouseId);

            if ($inventory !== null) {
                $data = $this->serializeWarehouseInventory($inventory);
                $this->setWarehouseInventory($warehouseId, $data, self::DEFAULT_TTL);
            }
        }

        $this->logger->debug('Warmed warehouse inventory cache', [
            'warehouses_warmed' => count($warehouseIds),
        ]);
    }

    public function getDeliveryEstimate(int $originZip, int $destZip, string $countryCode, string $carrier, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildDeliveryEstimateCacheKey($originZip, $destZip, $countryCode, $carrier);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'delivery_estimate']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'delivery_estimate']);

        $estimate = $this->shippingRateRepository->findDeliveryEstimate($originZip, $destZip, $countryCode, $carrier);

        if ($estimate === null) {
            return null;
        }

        $data = $this->serializeDeliveryEstimate($estimate);
        $this->setDeliveryEstimate($originZip, $destZip, $countryCode, $carrier, $data);

        return $data;
    }

    public function setDeliveryEstimate(int $originZip, int $destZip, string $countryCode, string $carrier, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildDeliveryEstimateCacheKey($originZip, $destZip, $countryCode, $carrier);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached delivery estimate', [
            'origin' => $originZip,
            'destination' => $destZip,
            'carrier' => $carrier,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateDeliveryEstimate(int $originZip, int $destZip, string $countryCode, string $carrier): void
    {
        $cacheKey = $this->buildDeliveryEstimateCacheKey($originZip, $destZip, $countryCode, $carrier);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated delivery estimate cache', [
            'origin' => $originZip,
            'destination' => $destZip,
            'carrier' => $carrier,
        ]);
    }

    public function refreshDeliveryEstimate(int $originZip, int $destZip, string $countryCode, string $carrier): void
    {
        $estimate = $this->shippingRateRepository->findDeliveryEstimate($originZip, $destZip, $countryCode, $carrier);

        if ($estimate === null) {
            $this->cache->delete($this->buildDeliveryEstimateCacheKey($originZip, $destZip, $countryCode, $carrier));
            return;
        }

        $data = $this->serializeDeliveryEstimate($estimate);
        $this->setDeliveryEstimate($originZip, $destZip, $countryCode, $carrier, $data);

        $this->logger->debug('Refreshed delivery estimate cache', [
            'origin' => $originZip,
            'destination' => $destZip,
            'carrier' => $carrier,
        ]);
    }

    public function handleShippingRateChange(int $carrierId): void
    {
        $this->metrics->increment('cache.invalidation', [
            'type' => 'shipping_rate_change',
            'carrier_id' => (string) $carrierId,
        ]);

        $this->logger->info('Handled shipping rate change cache invalidation', [
            'carrier_id' => $carrierId,
        ]);
    }

    public function handleWarehouseInventoryUpdate(int $warehouseId): void
    {
        $this->invalidateWarehouseInventory($warehouseId);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'warehouse_inventory_update',
            'warehouse_id' => (string) $warehouseId,
        ]);

        $this->logger->info('Handled warehouse inventory update cache invalidation', [
            'warehouse_id' => $warehouseId,
        ]);
    }

    public function handleCarrierConfigChange(int $carrierId): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'rates', '*', (string) $carrierId, '*');
        $this->cache->deleteByPattern($pattern);

        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'estimate', '*', (string) $carrierId, '*');
        $this->cache->deleteByPattern($pattern);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'carrier_config_change',
            'carrier_id' => (string) $carrierId,
        ]);

        $this->logger->info('Handled carrier config change cache invalidation', [
            'carrier_id' => $carrierId,
        ]);
    }

    public function handleGlobalShippingUpdate(): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, '*');
        $this->cache->deleteByPattern($pattern);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'global_shipping_update',
        ]);

        $this->logger->info('Handled global shipping update cache invalidation');
    }

    private function buildShippingRatesCacheKey(int $origin, int $dest, string $country): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'rates', (string) $origin, (string) $dest, $country);
    }

    private function buildWarehouseInventoryCacheKey(int $warehouseId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'warehouse', (string) $warehouseId, 'inventory');
    }

    private function buildDeliveryEstimateCacheKey(int $origin, int $dest, string $country, string $carrier): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'estimate', (string) $origin, (string) $dest, $country, $carrier);
    }

    private function serializeShippingRates(array $rates): array
    {
        $result = [];
        foreach ($rates as $rate) {
            $result[$rate->getCarrier()] = [
                'base_rate' => $rate->getBaseRate(),
                'per_pound_rate' => $rate->getPerPoundRate(),
                'estimated_days' => $rate->getEstimatedDays(),
            ];
        }
        return $result;
    }

    private function serializeWarehouseInventory(array $inventory): array
    {
        $result = [];
        foreach ($inventory as $item) {
            $result[$item['product_id']] = [
                'quantity' => $item['quantity'],
                'reserved' => $item['reserved'],
                'available' => $item['available'],
            ];
        }
        return $result;
    }

    private function serializeDeliveryEstimate(object $estimate): array
    {
        return [
            'min_days' => $estimate->getMinDays(),
            'max_days' => $estimate->getMaxDays(),
            'delivery_date' => $estimate->getDeliveryDate()?->format('Y-m-d'),
            'cutoff_time' => $estimate->getCutoffTime(),
        ];
    }
}
