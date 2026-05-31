<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\RewardRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class RewardLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly RewardRepository $rewardRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedReward(int $rewardId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildRewardCacheKey($rewardId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'reward', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'reward', 'locale' => $locale]);

        $reward = $this->rewardRepository->find($rewardId);

        if ($reward === null) {
            return null;
        }

        $data = $this->translateReward($reward, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getAvailableRewards(?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildAvailableRewardsCacheKey($locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $rewards = $this->rewardRepository->findAvailable();

        $results = [];
        foreach ($rewards as $reward) {
            $results[] = $this->translateReward($reward, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateReward(int $rewardId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildRewardCacheKey($rewardId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildAvailableRewardsCacheKey($l));
        }

        $this->logger->debug('Invalidated reward localization', [
            'reward_id' => $rewardId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('reward:*:' . $locale);

        $this->logger->info('Invalidated all rewards for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateRewardTranslation(int $rewardId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildRewardCacheKey($rewardId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', [
            'type' => 'reward',
            'reward_id' => (string) $rewardId,
            'locale' => $locale,
        ]);
    }

    private function buildRewardCacheKey(int $rewardId, string $locale): string
    {
        return "reward:{$rewardId}:{$locale}";
    }

    private function buildAvailableRewardsCacheKey(string $locale): string
    {
        return "reward:available:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateReward(object $reward, string $locale): array
    {
        return [
            'id' => $reward->getId(),
            'type' => $reward->getType(),
            'name' => $this->translator->translate($reward->getNameKey(), $locale),
            'description' => $this->translator->translate($reward->getDescriptionKey(), $locale),
            'points_cost' => $reward->getPointsCost(),
            'terms' => $this->translator->translate($reward->getTermsKey(), $locale),
            'locale' => $locale,
        ];
    }
}
