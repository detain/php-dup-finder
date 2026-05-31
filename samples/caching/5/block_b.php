<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\InventoryRepository;
use App\Repository\ProductRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class InventoryCacheHandler
{
    private const CACHE_PREFIX = 'inventory';
    private const DEFAULT_TTL = 900;
    private const STALE_TTL = 300;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly InventoryRepository $inventoryRepository,
        private readonly ProductRepository $productRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getInventoryLevel(int $productId, int $warehouseId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildInventoryLevelCacheKey($productId, $warehouseId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'inventory_level']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'inventory_level']);

        $level = $this->inventoryRepository->findLevel($productId, $warehouseId);
        if ($level === null) {
            return null;
        }

        $data = $this->serializeInventoryLevel($level);
        $this->setInventoryLevel($productId, $warehouseId, $data);
        return $data;
    }

    public function setInventoryLevel(int $productId, int $warehouseId, array $data, ?int $ttl = null): void
    {
        $this->cache->set($this->buildInventoryLevelCacheKey($productId, $warehouseId), $data, $ttl ?? self::DEFAULT_TTL);
    }

    public function invalidateInventoryLevel(int $productId, int $warehouseId): void
    {
        $this->cache->delete($this->buildInventoryLevelCacheKey($productId, $warehouseId));
    }

    public function refreshInventoryLevel(int $productId, int $warehouseId): void
    {
        $level = $this->inventoryRepository->findLevel($productId, $warehouseId);
        if ($level === null) {
            $this->cache->delete($this->buildInventoryLevelCacheKey($productId, $warehouseId));
            return;
        }
        $this->setInventoryLevel($productId, $warehouseId, $this->serializeInventoryLevel($level));
    }

    public function warmProductInventory(int $productId): void
    {
        $warehouses = $this->inventoryRepository->findWarehousesWithProduct($productId);
        foreach ($warehouses as $warehouseId) {
            $level = $this->inventoryRepository->findLevel($productId, $warehouseId);
            if ($level !== null) {
                $this->setInventoryLevel($productId, $warehouseId, $this->serializeInventoryLevel($level), self::DEFAULT_TTL);
            }
        }
    }

    public function getAvailableQuantity(int $productId, bool $allowStale = false): ?int
    {
        $cacheKey = $this->buildAvailableQuantityCacheKey($productId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'available_quantity']);
            return $cached['quantity'];
        }

        $this->metrics->increment('cache.miss', ['type' => 'available_quantity']);

        $quantity = $this->inventoryRepository->calculateAvailableQuantity($productId);
        $this->setAvailableQuantity($productId, $quantity);
        return $quantity;
    }

    public function setAvailableQuantity(int $productId, int $quantity, ?int $ttl = null): void
    {
        $this->cache->set($this->buildAvailableQuantityCacheKey($productId), ['quantity' => $quantity], $ttl ?? 300);
    }

    public function invalidateAvailableQuantity(int $productId): void
    {
        $this->cache->delete($this->buildAvailableQuantityCacheKey($productId));
    }

    public function refreshAvailableQuantity(int $productId): void
    {
        $quantity = $this->inventoryRepository->calculateAvailableQuantity($productId);
        $this->setAvailableQuantity($productId, $quantity);
    }

    public function getWarehouseStock(int $warehouseId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildWarehouseStockCacheKey($warehouseId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'warehouse_stock']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'warehouse_stock']);

        $stock = $this->inventoryRepository->findWarehouseStock($warehouseId);
        if ($stock === null) {
            return null;
        }

        $data = $this->serializeWarehouseStock($stock);
        $this->setWarehouseStock($warehouseId, $data);
        return $data;
    }

    public function setWarehouseStock(int $warehouseId, array $data, ?int $ttl = null): void
    {
        $this->cache->set($this->buildWarehouseStockCacheKey($warehouseId), $data, $ttl ?? self::DEFAULT_TTL);
    }

    public function invalidateWarehouseStock(int $warehouseId): void
    {
        $this->cache->delete($this->buildWarehouseStockCacheKey($warehouseId));
    }

    public function refreshWarehouseStock(int $warehouseId): void
    {
        $stock = $this->inventoryRepository->findWarehouseStock($warehouseId);
        if ($stock === null) {
            $this->cache->delete($this->buildWarehouseStockCacheKey($warehouseId));
            return;
        }
        $this->setWarehouseStock($warehouseId, $this->serializeWarehouseStock($stock));
    }

    public function getLowStockAlerts(int $warehouseId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildLowStockAlertsCacheKey($warehouseId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'low_stock_alerts']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'low_stock_alerts']);

        $alerts = $this->inventoryRepository->findLowStockAlerts($warehouseId);
        if ($alerts === null) {
            return null;
        }

        $data = $this->serializeLowStockAlerts($alerts);
        $this->setLowStockAlerts($warehouseId, $data);
        return $data;
    }

    public function setLowStockAlerts(int $warehouseId, array $data, ?int $ttl = null): void
    {
        $this->cache->set($this->buildLowStockAlertsCacheKey($warehouseId), $data, $ttl ?? 600);
    }

    public function invalidateLowStockAlerts(int $warehouseId): void
    {
        $this->cache->delete($this->buildLowStockAlertsCacheKey($warehouseId));
    }

    public function getReservation(int $reservationId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildReservationCacheKey($reservationId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'inventory_reservation']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'inventory_reservation']);

        $reservation = $this->inventoryRepository->findReservation($reservationId);
        if ($reservation === null) {
            return null;
        }

        $data = $this->serializeReservation($reservation);
        $this->setReservation($reservationId, $data);
        return $data;
    }

    public function setReservation(int $reservationId, array $data, ?int $ttl = null): void
    {
        $this->cache->set($this->buildReservationCacheKey($reservationId), $data, $ttl ?? 300);
    }

    public function invalidateReservation(int $reservationId): void
    {
        $this->cache->delete($this->buildReservationCacheKey($reservationId));
    }

    public function handleInventoryUpdate(int $productId, int $warehouseId): void
    {
        $this->invalidateInventoryLevel($productId, $warehouseId);
        $this->invalidateAvailableQuantity($productId);
        $this->invalidateWarehouseStock($warehouseId);
        $this->invalidateLowStockAlerts($warehouseId);
        $this->logger->info('Handled inventory update cache invalidation', ['product_id' => $productId]);
    }

    public function handleReservationChange(int $reservationId, int $productId): void
    {
        $this->invalidateReservation($reservationId);
        $this->invalidateAvailableQuantity($productId);
        $this->logger->info('Handled reservation change cache invalidation', ['reservation_id' => $reservationId]);
    }

    private function buildInventoryLevelCacheKey(int $productId, int $warehouseId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'level', (string) $productId, (string) $warehouseId);
    }

    private function buildAvailableQuantityCacheKey(int $productId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'available', (string) $productId);
    }

    private function buildWarehouseStockCacheKey(int $warehouseId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'warehouse', (string) $warehouseId, 'stock');
    }

    private function buildLowStockAlertsCacheKey(int $warehouseId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'warehouse', (string) $warehouseId, 'alerts');
    }

    private function buildReservationCacheKey(int $reservationId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'reservation', (string) $reservationId);
    }

    private function serializeInventoryLevel(object $level): array
    {
        return [
            'product_id' => $level->getProductId(),
            'warehouse_id' => $level->getWarehouseId(),
            'quantity' => $level->getQuantity(),
            'reserved' => $level->getReserved(),
            'available' => $level->getAvailable(),
        ];
    }

    private function serializeWarehouseStock(array $stock): array
    {
        return array_map(fn($item) => [
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'reserved' => $item['reserved'],
        ], $stock);
    }

    private function serializeLowStockAlerts(array $alerts): array
    {
        return array_map(fn($alert) => [
            'product_id' => $alert->getProductId(),
            'current_quantity' => $alert->getCurrentQuantity(),
            'threshold' => $alert->getThreshold(),
        ], $alerts);
    }

    private function serializeReservation(object $reservation): array
    {
        return [
            'id' => $reservation->getId(),
            'product_id' => $reservation->getProductId(),
            'quantity' => $reservation->getQuantity(),
            'expires_at' => $reservation->getExpiresAt()?->format(\DATE_ATOM),
        ];
    }
}
