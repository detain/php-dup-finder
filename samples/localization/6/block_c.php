<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\PageRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class PageLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly PageRepository $pageRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedPage(int $pageId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildPageCacheKey($pageId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'page', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'page', 'locale' => $locale]);

        $page = $this->pageRepository->find($pageId);

        if ($page === null) {
            return null;
        }

        $data = $this->translatePage($page, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getPageByPath(string $path, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildPathCacheKey($path, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $page = $this->pageRepository->findByPath($path);

        if ($page === null) {
            return null;
        }

        $data = $this->translatePage($page, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function invalidatePage(int $pageId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildPageCacheKey($pageId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        $page = $this->pageRepository->find($pageId);
        if ($page !== null) {
            foreach (self::SUPPORTED_LOCALES as $l) {
                $pathKey = $this->buildPathCacheKey($page->getPath(), $l);
                $this->translator->invalidateCache($pathKey);
            }
        }

        $this->logger->debug('Invalidated page localization', [
            'page_id' => $pageId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('page:*:' . $locale);

        $this->logger->info('Invalidated all pages for locale', [
            'locale' => $locale,
        ]);
    }

    public function updatePageTranslation(int $pageId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildPageCacheKey($pageId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $page = $this->pageRepository->find($pageId);
        if ($page !== null) {
            $pathKey = $this->buildPathCacheKey($page->getPath(), $locale);
            $this->translator->cacheTranslation($pathKey, $translatedData);
        }

        $this->metrics->increment('localization.update', [
            'type' => 'page',
            'page_id' => (string) $pageId,
            'locale' => $locale,
        ]);
    }

    private function buildPageCacheKey(int $pageId, string $locale): string
    {
        return "page:{$pageId}:{$locale}";
    }

    private function buildPathCacheKey(string $path, string $locale): string
    {
        return "page:path:{$path}:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translatePage(object $page, string $locale): array
    {
        return [
            'id' => $page->getId(),
            'path' => $page->getPath(),
            'title' => $this->translator->translate($page->getTitleKey(), $locale),
            'content' => $this->translator->translate($page->getContentKey(), $locale),
            'meta_title' => $this->translator->translate($page->getMetaTitleKey(), $locale),
            'meta_description' => $this->translator->translate($page->getMetaDescriptionKey(), $locale),
            'locale' => $locale,
        ];
    }
}
