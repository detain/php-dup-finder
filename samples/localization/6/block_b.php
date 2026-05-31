<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\SupportArticleRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class SupportArticleLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly SupportArticleRepository $articleRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedArticle(int $articleId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildArticleCacheKey($articleId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'support_article', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'support_article', 'locale' => $locale]);

        $article = $this->articleRepository->find($articleId);

        if ($article === null) {
            return null;
        }

        $data = $this->translateArticle($article, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getArticleBySlug(string $slug, ?string $locale = null): ?array
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

        $article = $this->articleRepository->findBySlug($slug);

        if ($article === null) {
            return null;
        }

        $data = $this->translateArticle($article, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getArticlesByTopic(string $topicSlug, ?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildTopicCacheKey($topicSlug, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $articles = $this->articleRepository->findByTopic($topicSlug);

        $results = [];
        foreach ($articles as $article) {
            $results[] = $this->translateArticle($article, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateArticle(int $articleId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildArticleCacheKey($articleId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        $article = $this->articleRepository->find($articleId);
        if ($article !== null) {
            foreach (self::SUPPORTED_LOCALES as $l) {
                $slugKey = $this->buildSlugCacheKey($article->getSlug(), $l);
                $this->translator->invalidateCache($slugKey);
            }
        }

        $this->logger->debug('Invalidated support article localization', [
            'article_id' => $articleId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('support_article:*:' . $locale);

        $this->logger->info('Invalidated all support articles for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateArticleTranslation(int $articleId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildArticleCacheKey($articleId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $article = $this->articleRepository->find($articleId);
        if ($article !== null) {
            $slugKey = $this->buildSlugCacheKey($article->getSlug(), $locale);
            $this->translator->cacheTranslation($slugKey, $translatedData);
        }

        $this->metrics->increment('localization.update', [
            'type' => 'support_article',
            'article_id' => (string) $articleId,
            'locale' => $locale,
        ]);
    }

    private function buildArticleCacheKey(int $articleId, string $locale): string
    {
        return "support_article:{$articleId}:{$locale}";
    }

    private function buildSlugCacheKey(string $slug, string $locale): string
    {
        return "support_article:slug:{$slug}:{$locale}";
    }

    private function buildTopicCacheKey(string $topicSlug, string $locale): string
    {
        return "support_article:topic:{$topicSlug}:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateArticle(object $article, string $locale): array
    {
        return [
            'id' => $article->getId(),
            'slug' => $article->getSlug(),
            'topic_slug' => $article->getTopicSlug(),
            'title' => $this->translator->translate($article->getTitleKey(), $locale),
            'content' => $this->translator->translate($article->getContentKey(), $locale),
            'summary' => $this->translator->translate($article->getSummaryKey(), $locale),
            'related_article_ids' => $article->getRelatedArticleIds(),
            'locale' => $locale,
        ];
    }
}
