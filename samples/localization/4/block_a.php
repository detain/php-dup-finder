<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\CampaignRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class CampaignLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly CampaignRepository $campaignRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedCampaign(int $campaignId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildCampaignCacheKey($campaignId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'campaign', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'campaign', 'locale' => $locale]);

        $campaign = $this->campaignRepository->find($campaignId);

        if ($campaign === null) {
            return null;
        }

        $data = $this->translateCampaign($campaign, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getCampaignBySlug(string $slug, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildSlugCacheKey($slug, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $campaign = $this->campaignRepository->findBySlug($slug);

        if ($campaign === null) {
            return null;
        }

        $data = $this->translateCampaign($campaign, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getActiveCampaigns(?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildActiveCampaignsCacheKey($locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $campaigns = $this->campaignRepository->findActive();

        $results = [];
        foreach ($campaigns as $campaign) {
            $results[] = $this->translateCampaign($campaign, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateCampaign(int $campaignId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildCampaignCacheKey($campaignId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        $campaign = $this->campaignRepository->find($campaignId);
        if ($campaign !== null) {
            foreach (self::SUPPORTED_LOCALES as $l) {
                $slugKey = $this->buildSlugCacheKey($campaign->getSlug(), $l);
                $this->translator->invalidateCache($slugKey);
            }
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildActiveCampaignsCacheKey($l));
        }

        $this->logger->debug('Invalidated campaign localization', [
            'campaign_id' => $campaignId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('campaign:*:' . $locale);

        $this->logger->info('Invalidated all campaigns for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateCampaignTranslation(int $campaignId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildCampaignCacheKey($campaignId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $campaign = $this->campaignRepository->find($campaignId);
        if ($campaign !== null) {
            $slugKey = $this->buildSlugCacheKey($campaign->getSlug(), $locale);
            $this->translator->cacheTranslation($slugKey, $translatedData);
        }

        $this->metrics->increment('localization.update', [
            'type' => 'campaign',
            'campaign_id' => (string) $campaignId,
            'locale' => $locale,
        ]);
    }

    private function buildCampaignCacheKey(int $campaignId, string $locale): string
    {
        return "campaign:{$campaignId}:{$locale}";
    }

    private function buildSlugCacheKey(string $slug, string $locale): string
    {
        return "campaign:slug:{$slug}:{$locale}";
    }

    private function buildActiveCampaignsCacheKey(string $locale): string
    {
        return "campaign:active:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateCampaign(object $campaign, string $locale): array
    {
        return [
            'id' => $campaign->getId(),
            'slug' => $campaign->getSlug(),
            'name' => $this->translator->translate($campaign->getNameKey(), $locale),
            'headline' => $this->translator->translate($campaign->getHeadlineKey(), $locale),
            'description' => $this->translator->translate($campaign->getDescriptionKey(), $locale),
            'cta_text' => $this->translator->translate($campaign->getCtaTextKey(), $locale),
            'starts_at' => $campaign->getStartsAt()?->format(\DATE_ATOM),
            'ends_at' => $campaign->getEndsAt()?->format(\DATE_ATOM),
            'locale' => $locale,
        ];
    }
}
