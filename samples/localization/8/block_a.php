<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\BlogPostRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class BlogPostLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'ru'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly BlogPostRepository $postRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedPost(int $postId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildPostCacheKey($postId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'blog_post', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'blog_post', 'locale' => $locale]);

        $post = $this->postRepository->find($postId);

        if ($post === null) {
            return null;
        }

        $data = $this->translatePost($post, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getPostBySlug(string $slug, ?string $locale = null): ?array
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

        $post = $this->postRepository->findBySlug($slug);

        if ($post === null) {
            return null;
        }

        $data = $this->translatePost($post, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getRecentPosts(?string $locale = null, int $limit = 10): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildRecentPostsCacheKey($locale, $limit);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $posts = $this->postRepository->findRecent($limit);

        $results = [];
        foreach ($posts as $post) {
            $results[] = $this->translatePost($post, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidatePost(int $postId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildPostCacheKey($postId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        $post = $this->postRepository->find($postId);
        if ($post !== null) {
            foreach (self::SUPPORTED_LOCALES as $l) {
                $slugKey = $this->buildSlugCacheKey($post->getSlug(), $l);
                $this->translator->invalidateCache($slugKey);
            }
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildRecentPostsCacheKey($l, 10));
        }

        $this->logger->debug('Invalidated blog post localization', [
            'post_id' => $postId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('blog_post:*:' . $locale);

        $this->logger->info('Invalidated all blog posts for locale', [
            'locale' => $locale,
        ]);
    }

    public function updatePostTranslation(int $postId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildPostCacheKey($postId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $post = $this->postRepository->find($postId);
        if ($post !== null) {
            $slugKey = $this->buildSlugCacheKey($post->getSlug(), $locale);
            $this->translator->cacheTranslation($slugKey, $translatedData);
        }

        $this->metrics->increment('localization.update', [
            'type' => 'blog_post',
            'post_id' => (string) $postId,
            'locale' => $locale,
        ]);
    }

    private function buildPostCacheKey(int $postId, string $locale): string
    {
        return "blog_post:{$postId}:{$locale}";
    }

    private function buildSlugCacheKey(string $slug, string $locale): string
    {
        return "blog_post:slug:{$slug}:{$locale}";
    }

    private function buildRecentPostsCacheKey(string $locale, int $limit): string
    {
        return "blog_post:recent:{$locale}:{$limit}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translatePost(object $post, string $locale): array
    {
        return [
            'id' => $post->getId(),
            'slug' => $post->getSlug(),
            'author_id' => $post->getAuthorId(),
            'title' => $this->translator->translate($post->getTitleKey(), $locale),
            'excerpt' => $this->translator->translate($post->getExcerptKey(), $locale),
            'content' => $this->translator->translate($post->getContentKey(), $locale),
            'meta_title' => $this->translator->translate($post->getMetaTitleKey(), $locale),
            'meta_description' => $this->translator->translate($post->getMetaDescriptionKey(), $locale),
            'published_at' => $post->getPublishedAt()?->format(\DATE_ATOM),
            'locale' => $locale,
        ];
    }
}
