<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\ContentRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ContentLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'zh'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly ContentRepository $contentRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedContent(int $contentId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildContentCacheKey($contentId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'content', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'content', 'locale' => $locale]);

        $content = $this->contentRepository->find($contentId);

        if ($content === null) {
            return null;
        }

        $data = $this->translateContent($content, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getLocalizedContentList(array $contentIds, ?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $results = [];

        foreach ($contentIds as $contentId) {
            $localized = $this->getLocalizedContent($contentId, $locale);
            if ($localized !== null) {
                $results[$contentId] = $localized;
            }
        }

        return $results;
    }

    public function getContentBySlug(string $slug, ?string $locale = null): ?array
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

        $content = $this->contentRepository->findBySlug($slug);

        if ($content === null) {
            return null;
        }

        $data = $this->translateContent($content, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function searchLocalizedContent(string $query, ?string $locale = null, int $limit = 20): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $contents = $this->contentRepository->search($query, $limit);

        $results = [];
        foreach ($contents as $content) {
            $results[] = $this->translateContent($content, $locale);
        }

        return $results;
    }

    public function invalidateContentLocalization(int $contentId): void
    {
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildContentCacheKey($contentId, $locale);
            $this->translator->invalidateCache($cacheKey);
        }

        $content = $this->contentRepository->find($contentId);
        if ($content !== null) {
            $slugCacheKey = $this->buildSlugCacheKey($content->getSlug(), $locale ?? self::DEFAULT_LOCALE);
            $this->translator->invalidateCache($slugCacheKey);
        }

        $this->logger->debug('Invalidated content localization', [
            'content_id' => $contentId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('content:*:' . $locale);

        $this->logger->info('Invalidated all content for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateContentTranslation(int $contentId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildContentCacheKey($contentId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $content = $this->contentRepository->find($contentId);
        if ($content !== null) {
            $slugCacheKey = $this->buildSlugCacheKey($content->getSlug(), $locale);
            $this->translator->cacheTranslation($slugCacheKey, $translatedData);
        }

        $this->metrics->increment('localization.update', [
            'type' => 'content',
            'content_id' => (string) $contentId,
            'locale' => $locale,
        ]);
    }

    public function getAvailableTranslations(int $contentId): array
    {
        $translations = [];

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildContentCacheKey($contentId, $locale);
            $cached = $this->translator->getCachedTranslation($cacheKey);
            $translations[$locale] = $cached !== null;
        }

        return $translations;
    }

    public function getMissingTranslations(int $contentId): array
    {
        $available = $this->getAvailableTranslations($contentId);
        $missing = [];

        foreach ($available as $locale => $exists) {
            if (!$exists) {
                $missing[] = $locale;
            }
        }

        return $missing;
    }

    public function warmCacheForContent(int $contentId): void
    {
        $content = $this->contentRepository->find($contentId);

        if ($content === null) {
            return;
        }

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $data = $this->translateContent($content, $locale);
            $cacheKey = $this->buildContentCacheKey($contentId, $locale);
            $this->translator->cacheTranslation($cacheKey, $data);
        }

        $this->logger->debug('Warmed localization cache for content', [
            'content_id' => $contentId,
        ]);
    }

    public function formatLocalizedDate(int $contentId, string $dateField, ?string $locale = null): ?string
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();
        $content = $this->getLocalizedContent($contentId, $locale);

        if ($content === null || !isset($content[$dateField])) {
            return null;
        }

        return $this->translator->formatDate($content[$dateField], $locale);
    }

    public function formatLocalizedCurrency(int $contentId, string $amountField, ?string $locale = null): ?string
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();
        $content = $this->getLocalizedContent($contentId, $locale);

        if ($content === null || !isset($content[$amountField])) {
            return null;
        }

        return $this->translator->formatCurrency($content[$amountField], $locale);
    }

    public function getLocalizedUrl(int $contentId, ?string $locale = null): string
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();
        $content = $this->getLocalizedContent($contentId, $locale);

        if ($content === null) {
            return '/';
        }

        return '/' . $locale . '/content/' . ($content['slug'] ?? $contentId);
    }

    private function buildContentCacheKey(int $contentId, string $locale): string
    {
        return "content:{$contentId}:{$locale}";
    }

    private function buildSlugCacheKey(string $slug, string $locale): string
    {
        return "content:slug:{$slug}:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateContent(object $content, string $locale): array
    {
        $data = [
            'id' => $content->getId(),
            'slug' => $content->getSlug(),
            'title' => $this->translator->translate($content->getTitleKey(), $locale),
            'body' => $this->translator->translate($content->getBodyKey(), $locale),
            'excerpt' => $this->translator->translate($content->getExcerptKey(), $locale),
            'meta_title' => $this->translator->translate($content->getMetaTitleKey(), $locale),
            'meta_description' => $this->translator->translate($content->getMetaDescriptionKey(), $locale),
            'locale' => $locale,
            'created_at' => $content->getCreatedAt()?->format(\DATE_ATOM),
            'updated_at' => $content->getUpdatedAt()?->format(\DATE_ATOM),
        ];

        return $data;
    }
}
