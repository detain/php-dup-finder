<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\StaticPageRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class StaticPageLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'pl', 'ru', 'uk', 'tr'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly StaticPageRepository $pageRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedPage(string $pageKey, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildPageCacheKey($pageKey, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'static_page', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'static_page', 'locale' => $locale]);

        $page = $this->pageRepository->findByKey($pageKey);

        if ($page === null) {
            return null;
        }

        $data = $this->translatePage($page, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getAllPages(?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildAllPagesCacheKey($locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $pages = $this->pageRepository->findAll();

        $results = [];
        foreach ($pages as $page) {
            $results[$page->getKey()] = $this->translatePage($page, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidatePage(string $pageKey): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildPageCacheKey($pageKey, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildAllPagesCacheKey($l));
        }

        $this->logger->debug('Invalidated static page localization', [
            'page_key' => $pageKey,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('static_page:*:' . $locale);

        $this->logger->info('Invalidated all static pages for locale', [
            'locale' => $locale,
        ]);
    }

    public function updatePageTranslation(string $pageKey, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildPageCacheKey($pageKey, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', [
            'type' => 'static_page',
            'page_key' => $pageKey,
            'locale' => $locale,
        ]);
    }

    private function buildPageCacheKey(string $pageKey, string $locale): string
    {
        return "static_page:{$pageKey}:{$locale}";
    }

    private function buildAllPagesCacheKey(string $locale): string
    {
        return "static_page:all:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translatePage(object $page, string $locale): array
    {
        return [
            'key' => $page->getKey(),
            'title' => $this->translator->translate($page->getTitleKey(), $locale),
            'content' => $this->translator->translate($page->getContentKey(), $locale),
            'meta_title' => $this->translator->translate($page->getMetaTitleKey(), $locale),
            'meta_description' => $this->translator->translate($page->getMetaDescriptionKey(), $locale),
            'locale' => $locale,
        ];
    }
}
