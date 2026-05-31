<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\BannerRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class BannerLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly BannerRepository $bannerRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedBanner(int $bannerId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildBannerCacheKey($bannerId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'banner', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'banner', 'locale' => $locale]);

        $banner = $this->bannerRepository->find($bannerId);

        if ($banner === null) {
            return null;
        }

        $data = $this->translateBanner($banner, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getBannersByPosition(string $position, ?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildPositionCacheKey($position, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $banners = $this->bannerRepository->findByPosition($position);

        $results = [];
        foreach ($banners as $banner) {
            $results[] = $this->translateBanner($banner, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateBanner(int $bannerId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildBannerCacheKey($bannerId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        $banner = $this->bannerRepository->find($bannerId);
        if ($banner !== null) {
            foreach (self::SUPPORTED_LOCALES as $l) {
                $positionKey = $this->buildPositionCacheKey($banner->getPosition(), $l);
                $this->translator->invalidateCache($positionKey);
            }
        }

        $this->logger->debug('Invalidated banner localization', [
            'banner_id' => $bannerId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('banner:*:' . $locale);

        $this->logger->info('Invalidated all banners for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateBannerTranslation(int $bannerId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildBannerCacheKey($bannerId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', [
            'type' => 'banner',
            'banner_id' => (string) $bannerId,
            'locale' => $locale,
        ]);
    }

    private function buildBannerCacheKey(int $bannerId, string $locale): string
    {
        return "banner:{$bannerId}:{$locale}";
    }

    private function buildPositionCacheKey(string $position, string $locale): string
    {
        return "banner:position:{$position}:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateBanner(object $banner, string $locale): array
    {
        return [
            'id' => $banner->getId(),
            'position' => $banner->getPosition(),
            'title' => $this->translator->translate($banner->getTitleKey(), $locale),
            'subtitle' => $this->translator->translate($banner->getSubtitleKey(), $locale),
            'cta_text' => $this->translator->translate($banner->getCtaTextKey(), $locale),
            'image_url' => $banner->getImageUrl(),
            'link_url' => $banner->getLinkUrl(),
            'locale' => $locale,
        ];
    }
}
