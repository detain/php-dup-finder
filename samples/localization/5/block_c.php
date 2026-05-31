<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\FeatureFlagRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class FeatureFlagLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly FeatureFlagRepository $flagRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedFeatureFlag(string $flagKey, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildFlagCacheKey($flagKey, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'feature_flag', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'feature_flag', 'locale' => $locale]);

        $flag = $this->flagRepository->findByKey($flagKey);

        if ($flag === null) {
            return null;
        }

        $data = $this->translateFlag($flag, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getAllFeatureFlags(?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildAllFlagsCacheKey($locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $flags = $this->flagRepository->findAll();

        $results = [];
        foreach ($flags as $flag) {
            $results[$flag->getKey()] = $this->translateFlag($flag, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateFeatureFlag(string $flagKey): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildFlagCacheKey($flagKey, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildAllFlagsCacheKey($l));
        }

        $this->logger->debug('Invalidated feature flag localization', [
            'flag_key' => $flagKey,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('feature_flag:*:' . $locale);

        $this->logger->info('Invalidated all feature flags for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateFlagTranslation(string $flagKey, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildFlagCacheKey($flagKey, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', [
            'type' => 'feature_flag',
            'flag_key' => $flagKey,
            'locale' => $locale,
        ]);
    }

    private function buildFlagCacheKey(string $flagKey, string $locale): string
    {
        return "feature_flag:{$flagKey}:{$locale}";
    }

    private function buildAllFlagsCacheKey(string $locale): string
    {
        return "feature_flag:all:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateFlag(object $flag, string $locale): array
    {
        return [
            'key' => $flag->getKey(),
            'description' => $this->translator->translate($flag->getDescriptionKey(), $locale),
            'is_enabled' => $flag->isEnabled(),
            'locale' => $locale,
        ];
    }
}
