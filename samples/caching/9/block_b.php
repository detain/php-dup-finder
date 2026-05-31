<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\ShippingRateRepository;
use App\Repository\ZoneRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ShippingRateCacheHandler
{
    private const CACHE_PREFIX = 'shipping_rate';
    private const DEFAULT_TTL = 7200;
    private const STALE_TTL = 600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly ShippingRateRepository $rateRepository,
        private readonly ZoneRepository $zoneRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getShippingRate(int $rateId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildShippingRateCacheKey($rateId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'shipping_rate']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'shipping_rate']);
        $rate = $this->rateRepository->find($rateId);

        if ($rate === null) {
            return null;
        }

        $data = $this->serializeShippingRate($rate);
        $this->setShippingRate($rateId, $data);
        return $data;
    }

    public function setShippingRate(int $rateId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildShippingRateCacheKey($rateId);
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidateShippingRate(int $rateId): void
    {
        $cacheKey = $this->buildShippingRateCacheKey($rateId);
        $this->cache->delete($cacheKey);
    }

    public function invalidateZoneShippingRates(int $zoneId): void
    {
        $rates = $this->rateRepository->findByZoneId($zoneId);
        $cacheKeys = array_map(
            fn($rate) => $this->buildShippingRateCacheKey($rate->getId()),
            $rates
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateZoneShippingSummary($zoneId);
        $this->logger->info('Invalidated shipping rates for zone', [
            'zone_id' => $zoneId,
            'rate_count' => count($rates),
        ]);
    }

    public function refreshShippingRate(int $rateId): void
    {
        $cacheKey = $this->buildShippingRateCacheKey($rateId);
        $rate = $this->rateRepository->find($rateId);

        if ($rate === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeShippingRate($rate);
        $this->setShippingRate($rateId, $data);
    }

    public function warmZone(int $zoneId): void
    {
        $rates = $this->rateRepository->findByZoneId($zoneId);

        foreach ($rates as $rate) {
            $data = $this->serializeShippingRate($rate);
            $this->setShippingRate($rate->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed shipping rate cache for zone', [
            'zone_id' => $zoneId,
            'rates_warmed' => count($rates),
        ]);
    }

    public function handleCreateShippingRate(int $rateId): void
    {
        $rate = $this->rateRepository->find($rateId);
        if ($rate === null) {
            return;
        }

        $this->invalidateZoneShippingRates($rate->getZoneId());

        $this->metrics->increment('cache.invalidation', [
            'type' => 'create_shipping_rate',
            'rate_id' => (string) $rateId,
        ]);
    }

    public function handleUpdateShippingRate(int $rateId): void
    {
        $this->invalidateShippingRate($rateId);

        $rate = $this->rateRepository->find($rateId);
        if ($rate === null) {
            return;
        }

        $updateKeys = [
            $this->keyBuilder->build('shipping_rate', $rateId, 'delivery_times'),
            $this->keyBuilder->build('shipping_rate', $rateId, 'weight_ranges'),
        ];

        foreach ($updateKeys as $key) {
            $this->cache->delete($key);
        }

        $this->logger->info('Handled shipping rate update cache invalidation', [
            'rate_id' => $rateId,
        ]);
    }

    public function handleDeleteShippingRate(int $rateId): void
    {
        $rate = $this->rateRepository->find($rateId);
        if ($rate !== null) {
            $this->invalidateShippingRate($rateId);
            $this->invalidateZoneShippingRates($rate->getZoneId());
        }

        $this->logger->info('Handled shipping rate deletion cache invalidation', [
            'rate_id' => $rateId,
        ]);
    }

    public function handleActivateShippingRate(int $rateId): void
    {
        $this->invalidateShippingRate($rateId);

        $rate = $this->rateRepository->find($rateId);
        if ($rate !== null) {
            $this->invalidateZoneShippingRates($rate->getZoneId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'activate_shipping_rate',
            'rate_id' => (string) $rateId,
        ]);
    }

    public function handleDeactivateShippingRate(int $rateId): void
    {
        $this->invalidateShippingRate($rateId);

        $rate = $this->rateRepository->find($rateId);
        if ($rate !== null) {
            $this->invalidateZoneShippingRates($rate->getZoneId());
        }

        $this->logger->info('Handled deactivate shipping rate cache invalidation', [
            'rate_id' => $rateId,
        ]);
    }

    public function handleWeightRangeUpdate(int $rateId): void
    {
        $this->invalidateShippingRate($rateId);

        $weightKey = $this->keyBuilder->build('shipping_rate', $rateId, 'weight_ranges');
        $this->cache->delete($weightKey);

        $rate = $this->rateRepository->find($rateId);
        if ($rate !== null) {
            $this->invalidateZoneShippingRates($rate->getZoneId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'weight_range_update',
            'rate_id' => (string) $rateId,
        ]);
    }

    public function handleZoneUpdate(int $zoneId): void
    {
        $this->invalidateZoneShippingSummary($zoneId);

        $zoneKeys = [
            $this->keyBuilder->build('zone', $zoneId, 'countries'),
            $this->keyBuilder->build('zone', $zoneId, 'postcodes'),
        ];

        foreach ($zoneKeys as $key) {
            $this->cache->delete($key);
        }

        $this->invalidateZoneShippingRates($zoneId);

        $this->logger->info('Handled zone update cache invalidation', [
            'zone_id' => $zoneId,
        ]);
    }

    private function buildShippingRateCacheKey(int $rateId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'rate', $rateId);
    }

    private function buildZoneShippingSummaryCacheKey(int $zoneId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'zone', $zoneId, 'summary');
    }

    private function invalidateZoneShippingSummary(int $zoneId): void
    {
        $this->cache->delete($this->buildZoneShippingSummaryCacheKey($zoneId));
    }

    private function serializeShippingRate(object $rate): array
    {
        return [
            'id' => $rate->getId(),
            'zone_id' => $rate->getZoneId(),
            'name' => $rate->getName(),
            'price' => $rate->getPrice(),
            'currency' => $rate->getCurrency(),
            'min_weight' => $rate->getMinWeight(),
            'max_weight' => $rate->getMaxWeight(),
            'delivery_days_min' => $rate->getDeliveryDaysMin(),
            'delivery_days_max' => $rate->getDeliveryDaysMax(),
            'is_active' => $rate->isActive(),
        ];
    }
}
