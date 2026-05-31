<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\HelpArticleRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class HelpArticleLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'zh'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly HelpArticleRepository $articleRepository,
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
            $this->metrics->increment('localization.hit', ['type' => 'help_article', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'help_article', 'locale' => $locale]);

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

    public function searchArticles(string $query, ?string $locale = null, int $limit = 20): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $articles = $this->articleRepository->search($query, $limit);

        $results = [];
        foreach ($articles as $article) {
            $results[] = $this->translateArticle($article, $locale);
        }

        return $results;
    }

    public function getArticlesByCategory(string $categorySlug, ?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $articles = $this->articleRepository->findByCategorySlug($categorySlug);

        $results = [];
        foreach ($articles as $article) {
            $results[] = $this->translateArticle($article, $locale);
        }

        return $results;
    }

    public function invalidateArticle(int $articleId): void
    {
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildArticleCacheKey($articleId, $locale);
            $this->translator->invalidateCache($cacheKey);
        }

        $article = $this->articleRepository->find($articleId);
        if ($article !== null) {
            foreach (self::SUPPORTED_LOCALES as $locale) {
                $slugKey = $this->buildSlugCacheKey($article->getSlug(), $locale);
                $this->translator->invalidateCache($slugKey);
            }
        }

        $this->logger->debug('Invalidated help article localization', [
            'article_id' => $articleId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('help_article:*:' . $locale);

        $this->logger->info('Invalidated all help articles for locale', [
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
            'type' => 'help_article',
            'article_id' => (string) $articleId,
            'locale' => $locale,
        ]);
    }

    public function getAvailableTranslations(int $articleId): array
    {
        $translations = [];

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildArticleCacheKey($articleId, $locale);
            $cached = $this->translator->getCachedTranslation($cacheKey);
            $translations[$locale] = $cached !== null;
        }

        return $translations;
    }

    public function getMissingTranslations(int $articleId): array
    {
        $available = $this->getAvailableTranslations($articleId);
        $missing = [];

        foreach ($available as $locale => $exists) {
            if (!$exists) {
                $missing[] = $locale;
            }
        }

        return $missing;
    }

    public function warmCacheForArticle(int $articleId): void
    {
        $article = $this->articleRepository->find($articleId);

        if ($article === null) {
            return;
        }

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $data = $this->translateArticle($article, $locale);
            $this->translator->cacheTranslation($this->buildArticleCacheKey($articleId, $locale), $data);
            $this->translator->cacheTranslation($this->buildSlugCacheKey($article->getSlug(), $locale), $data);
        }

        $this->logger->debug('Warmed localization cache for help article', [
            'article_id' => $articleId,
        ]);
    }

    public function getRelatedArticles(int $articleId, ?string $locale = null, int $limit = 5): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();
        $article = $this->getLocalizedArticle($articleId, $locale);

        if ($article === null) {
            return [];
        }

        $relatedIds = $article['related_article_ids'] ?? [];
        $results = [];

        foreach (array_slice($relatedIds, 0, $limit) as $relatedId) {
            $related = $this->getLocalizedArticle($relatedId, $locale);
            if ($related !== null) {
                $results[] = $related;
            }
        }

        return $results;
    }

    private function buildArticleCacheKey(int $articleId, string $locale): string
    {
        return "help_article:{$articleId}:{$locale}";
    }

    private function buildSlugCacheKey(string $slug, string $locale): string
    {
        return "help_article:slug:{$slug}:{$locale}";
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
            'category_slug' => $article->getCategorySlug(),
            'title' => $this->translator->translate($article->getTitleKey(), $locale),
            'content' => $this->translator->translate($article->getContentKey(), $locale),
            'excerpt' => $this->translator->translate($article->getExcerptKey(), $locale),
            'meta_title' => $this->translator->translate($article->getMetaTitleKey(), $locale),
            'meta_description' => $this->translator->translate($article->getMetaDescriptionKey(), $locale),
            'related_article_ids' => $article->getRelatedArticleIds(),
            'locale' => $locale,
            'updated_at' => $article->getUpdatedAt()?->format(\DATE_ATOM),
        ];
    }
}
