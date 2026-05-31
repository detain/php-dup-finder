<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\NewsItemRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class NewsItemLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'ru'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly NewsItemRepository $newsRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedNewsItem(int $newsId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildNewsCacheKey($newsId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'news_item', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'news_item', 'locale' => $locale]);

        $newsItem = $this->newsRepository->find($newsId);

        if ($newsItem === null) {
            return null;
        }

        $data = $this->translateNewsItem($newsItem, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getLatestNews(?string $locale = null, int $limit = 10): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildLatestNewsCacheKey($locale, $limit);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $newsItems = $this->newsRepository->findLatest($limit);

        $results = [];
        foreach ($newsItems as $item) {
            $results[] = $this->translateNewsItem($item, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateNewsItem(int $newsId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildNewsCacheKey($newsId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildLatestNewsCacheKey($l, 10));
        }

        $this->logger->debug('Invalidated news item localization', [
            'news_id' => $newsId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('news_item:*:' . $locale);

        $this->logger->info('Invalidated all news items for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateNewsTranslation(int $newsId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildNewsCacheKey($newsId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', [
            'type' => 'news_item',
            'news_id' => (string) $newsId,
            'locale' => $locale,
        ]);
    }

    private function buildNewsCacheKey(int $newsId, string $locale): string
    {
        return "news_item:{$newsId}:{$locale}";
    }

    private function buildLatestNewsCacheKey(string $locale, int $limit): string
    {
        return "news_item:latest:{$locale}:{$limit}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateNewsItem(object $newsItem, string $locale): array
    {
        return [
            'id' => $newsItem->getId(),
            'headline' => $this->translator->translate($newsItem->getHeadlineKey(), $locale),
            'summary' => $this->translator->translate($newsItem->getSummaryKey(), $locale),
            'content' => $this->translator->translate($newsItem->getContentKey(), $locale),
            'source_name' => $newsItem->getSourceName(),
            'source_url' => $newsItem->getSourceUrl(),
            'published_at' => $newsItem->getPublishedAt()?->format(\DATE_ATOM),
            'locale' => $locale,
        ];
    }
}
