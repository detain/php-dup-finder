<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\DiscountCodeRepository;
use App\Repository\CampaignRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class DiscountCacheHandler
{
    private const CACHE_PREFIX = 'discount';
    private const DEFAULT_TTL = 3600;
    private const STALE_TTL = 600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly DiscountCodeRepository $discountRepository,
        private readonly CampaignRepository $campaignRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getDiscount(int $discountId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildDiscountCacheKey($discountId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'discount']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'discount']);
        $discount = $this->discountRepository->find($discountId);

        if ($discount === null) {
            return null;
        }

        $data = $this->serializeDiscount($discount);
        $this->setDiscount($discountId, $data);
        return $data;
    }

    public function setDiscount(int $discountId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildDiscountCacheKey($discountId);
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidateDiscount(int $discountId): void
    {
        $cacheKey = $this->buildDiscountCacheKey($discountId);
        $this->cache->delete($cacheKey);
    }

    public function invalidateCampaignDiscounts(int $campaignId): void
    {
        $discounts = $this->discountRepository->findByCampaignId($campaignId);
        $cacheKeys = array_map(
            fn($discount) => $this->buildDiscountCacheKey($discount->getId()),
            $discounts
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateCampaignSummary($campaignId);
        $this->logger->info('Invalidated discounts for campaign', [
            'campaign_id' => $campaignId,
            'discount_count' => count($discounts),
        ]);
    }

    public function refreshDiscount(int $discountId): void
    {
        $cacheKey = $this->buildDiscountCacheKey($discountId);
        $discount = $this->discountRepository->find($discountId);

        if ($discount === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeDiscount($discount);
        $this->setDiscount($discountId, $data);
    }

    public function warmCampaign(int $campaignId): void
    {
        $discounts = $this->discountRepository->findActiveByCampaignId($campaignId);

        foreach ($discounts as $discount) {
            $data = $this->serializeDiscount($discount);
            $this->setDiscount($discount->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed discount cache for campaign', [
            'campaign_id' => $campaignId,
            'discounts_warmed' => count($discounts),
        ]);
    }

    public function handleCreateDiscount(int $discountId): void
    {
        $discount = $this->discountRepository->find($discountId);
        if ($discount === null) {
            return;
        }

        $this->invalidateCampaignDiscounts($discount->getCampaignId());

        $this->metrics->increment('cache.invalidation', [
            'type' => 'create_discount',
            'discount_id' => (string) $discountId,
        ]);
    }

    public function handleUpdateDiscount(int $discountId): void
    {
        $this->invalidateDiscount($discountId);

        $discount = $this->discountRepository->find($discountId);
        if ($discount === null) {
            return;
        }

        $updateKeys = [
            $this->keyBuilder->build('discount', $discountId, 'usage_stats'),
            $this->keyBuilder->build('discount', $discountId, 'validation_rules'),
        ];

        foreach ($updateKeys as $key) {
            $this->cache->delete($key);
        }

        $this->logger->info('Handled discount update cache invalidation', [
            'discount_id' => $discountId,
        ]);
    }

    public function handleDeleteDiscount(int $discountId): void
    {
        $discount = $this->discountRepository->find($discountId);
        if ($discount !== null) {
            $this->invalidateDiscount($discountId);
            $this->invalidateCampaignDiscounts($discount->getCampaignId());
        }

        $this->logger->info('Handled discount deletion cache invalidation', [
            'discount_id' => $discountId,
        ]);
    }

    public function handleActivateDiscount(int $discountId): void
    {
        $this->invalidateDiscount($discountId);

        $discount = $this->discountRepository->find($discountId);
        if ($discount !== null) {
            $this->invalidateCampaignDiscounts($discount->getCampaignId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'activate_discount',
            'discount_id' => (string) $discountId,
        ]);
    }

    public function handleDeactivateDiscount(int $discountId): void
    {
        $this->invalidateDiscount($discountId);

        $discount = $this->discountRepository->find($discountId);
        if ($discount !== null) {
            $this->invalidateCampaignDiscounts($discount->getCampaignId());
        }

        $this->logger->info('Handled deactivate discount cache invalidation', [
            'discount_id' => $discountId,
        ]);
    }

    public function handleUsageThreshold(int $discountId): void
    {
        $this->invalidateDiscount($discountId);

        $usageKey = $this->keyBuilder->build('discount', $discountId, 'usage_stats');
        $this->cache->delete($usageKey);

        $discount = $this->discountRepository->find($discountId);
        if ($discount !== null) {
            $this->invalidateCampaignDiscounts($discount->getCampaignId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'usage_threshold',
            'discount_id' => (string) $discountId,
        ]);
    }

    public function handleCampaignUpdate(int $campaignId): void
    {
        $this->invalidateCampaignSummary($campaignId);

        $campaignKeys = [
            $this->keyBuilder->build('campaign', $campaignId, 'stats'),
            $this->keyBuilder->build('campaign', $campaignId, 'active_discounts'),
        ];

        foreach ($campaignKeys as $key) {
            $this->cache->delete($key);
        }

        $this->invalidateCampaignDiscounts($campaignId);

        $this->logger->info('Handled campaign update cache invalidation', [
            'campaign_id' => $campaignId,
        ]);
    }

    private function buildDiscountCacheKey(int $discountId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'discount', $discountId);
    }

    private function buildCampaignSummaryCacheKey(int $campaignId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'campaign', $campaignId, 'summary');
    }

    private function invalidateCampaignSummary(int $campaignId): void
    {
        $this->cache->delete($this->buildCampaignSummaryCacheKey($campaignId));
    }

    private function serializeDiscount(object $discount): array
    {
        return [
            'id' => $discount->getId(),
            'campaign_id' => $discount->getCampaignId(),
            'code' => $discount->getCode(),
            'type' => $discount->getType(),
            'value' => $discount->getValue(),
            'min_purchase' => $discount->getMinPurchase(),
            'max_uses' => $discount->getMaxUses(),
            'uses_count' => $discount->getUsesCount(),
            'starts_at' => $discount->getStartsAt()?->format(\DATE_ATOM),
            'expires_at' => $discount->getExpiresAt()?->format(\DATE_ATOM),
            'is_active' => $discount->isActive(),
        ];
    }
}
