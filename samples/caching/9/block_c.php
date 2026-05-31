<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\TaxRateRepository;
use App\Repository\RegionRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class TaxRateCacheHandler
{
    private const CACHE_PREFIX = 'tax_rate';
    private const DEFAULT_TTL = 14400;
    private const STALE_TTL = 3600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly TaxRateRepository $rateRepository,
        private readonly RegionRepository $regionRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getTaxRate(int $rateId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildTaxRateCacheKey($rateId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'tax_rate']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'tax_rate']);
        $rate = $this->rateRepository->find($rateId);

        if ($rate === null) {
            return null;
        }

        $data = $this->serializeTaxRate($rate);
        $this->setTaxRate($rateId, $data);
        return $data;
    }

    public function setTaxRate(int $rateId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildTaxRateCacheKey($rateId);
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidateTaxRate(int $rateId): void
    {
        $cacheKey = $this->buildTaxRateCacheKey($rateId);
        $this->cache->delete($cacheKey);
    }

    public function invalidateRegionTaxRates(int $regionId): void
    {
        $rates = $this->rateRepository->findByRegionId($regionId);
        $cacheKeys = array_map(
            fn($rate) => $this->buildTaxRateCacheKey($rate->getId()),
            $rates
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateRegionTaxSummary($regionId);
        $this->logger->info('Invalidated tax rates for region', [
            'region_id' => $regionId,
            'rate_count' => count($rates),
        ]);
    }

    public function refreshTaxRate(int $rateId): void
    {
        $cacheKey = $this->buildTaxRateCacheKey($rateId);
        $rate = $this->rateRepository->find($rateId);

        if ($rate === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeTaxRate($rate);
        $this->setTaxRate($rateId, $data);
    }

    public function warmRegion(int $regionId): void
    {
        $rates = $this->rateRepository->findByRegionId($regionId);

        foreach ($rates as $rate) {
            $data = $this->serializeTaxRate($rate);
            $this->setTaxRate($rate->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed tax rate cache for region', [
            'region_id' => $regionId,
            'rates_warmed' => count($rates),
        ]);
    }

    public function handleCreateTaxRate(int $rateId): void
    {
        $rate = $this->rateRepository->find($rateId);
        if ($rate === null) {
            return;
        }

        $this->invalidateRegionTaxRates($rate->getRegionId());

        $this->metrics->increment('cache.invalidation', [
            'type' => 'create_tax_rate',
            'rate_id' => (string) $rateId,
        ]);
    }

    public function handleUpdateTaxRate(int $rateId): void
    {
        $this->invalidateTaxRate($rateId);

        $rate = $this->rateRepository->find($rateId);
        if ($rate === null) {
            return;
        }

        $updateKeys = [
            $this->keyBuilder->build('tax_rate', $rateId, 'exemptions'),
            $this->keyBuilder->build('tax_rate', $rateId, 'compounds'),
        ];

        foreach ($updateKeys as $key) {
            $this->cache->delete($key);
        }

        $this->logger->info('Handled tax rate update cache invalidation', [
            'rate_id' => $rateId,
        ]);
    }

    public function handleDeleteTaxRate(int $rateId): void
    {
        $rate = $this->rateRepository->find($rateId);
        if ($rate !== null) {
            $this->invalidateTaxRate($rateId);
            $this->invalidateRegionTaxRates($rate->getRegionId());
        }

        $this->logger->info('Handled tax rate deletion cache invalidation', [
            'rate_id' => $rateId,
        ]);
    }

    public function handleActivateTaxRate(int $rateId): void
    {
        $this->invalidateTaxRate($rateId);

        $rate = $this->rateRepository->find($rateId);
        if ($rate !== null) {
            $this->invalidateRegionTaxRates($rate->getRegionId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'activate_tax_rate',
            'rate_id' => (string) $rateId,
        ]);
    }

    public function handleDeactivateTaxRate(int $rateId): void
    {
        $this->invalidateTaxRate($rateId);

        $rate = $this->rateRepository->find($rateId);
        if ($rate !== null) {
            $this->invalidateRegionTaxRates($rate->getRegionId());
        }

        $this->logger->info('Handled deactivate tax rate cache invalidation', [
            'rate_id' => $rateId,
        ]);
    }

    public function handleTaxLawChange(int $regionId): void
    {
        $this->invalidateRegionTaxSummary($regionId);

        $lawKeys = [
            $this->keyBuilder->build('region', $regionId, 'tax_rules'),
            $this->keyBuilder->build('region', $regionId, 'exemption_codes'),
        ];

        foreach ($lawKeys as $key) {
            $this->cache->delete($key);
        }

        $this->invalidateRegionTaxRates($regionId);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'tax_law_change',
            'region_id' => (string) $regionId,
        ]);
    }

    public function handleRegionUpdate(int $regionId): void
    {
        $this->invalidateRegionTaxSummary($regionId);

        $regionKeys = [
            $this->keyBuilder->build('region', $regionId, 'tax_authority'),
            $this->keyBuilder->build('region', $regionId, 'tax_ids'),
        ];

        foreach ($regionKeys as $key) {
            $this->cache->delete($key);
        }

        $this->invalidateRegionTaxRates($regionId);

        $this->logger->info('Handled region update cache invalidation', [
            'region_id' => $regionId,
        ]);
    }

    private function buildTaxRateCacheKey(int $rateId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'rate', $rateId);
    }

    private function buildRegionTaxSummaryCacheKey(int $regionId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'region', $regionId, 'summary');
    }

    private function invalidateRegionTaxSummary(int $regionId): void
    {
        $this->cache->delete($this->buildRegionTaxSummaryCacheKey($regionId));
    }

    private function serializeTaxRate(object $rate): array
    {
        return [
            'id' => $rate->getId(),
            'region_id' => $rate->getRegionId(),
            'name' => $rate->getName(),
            'rate' => $rate->getRate(),
            'rate_type' => $rate->getRateType(),
            'applicable_on' => $rate->getApplicableOn(),
            'is_compound' => $rate->isCompound(),
            'is_active' => $rate->isActive(),
            'effective_from' => $rate->getEffectiveFrom()?->format(\DATE_ATOM),
            'effective_to' => $rate->getEffectiveTo()?->format(\DATE_ATOM),
        ];
    }
}
