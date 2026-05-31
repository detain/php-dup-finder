<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\ArticleRepository;
use App\Repository\AuthorRepository;
use App\Repository\CategoryRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ArticleCacheHandler
{
    private const CACHE_PREFIX = 'article';
    private const DEFAULT_TTL = 3600;
    private const STALE_TTL = 600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly ArticleRepository $articleRepository,
        private readonly AuthorRepository $authorRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getArticle(int $articleId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildArticleCacheKey($articleId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'article']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'article']);
        $article = $this->articleRepository->find($articleId);

        if ($article === null) {
            return null;
        }

        $data = $this->serializeArticle($article);
        $this->setArticle($articleId, $data);
        return $data;
    }

    public function setArticle(int $articleId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildArticleCacheKey($articleId);
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidateArticle(int $articleId): void
    {
        $cacheKey = $this->buildArticleCacheKey($articleId);
        $this->cache->delete($cacheKey);
    }

    public function invalidateAuthorArticles(int $authorId): void
    {
        $articles = $this->articleRepository->findByAuthorId($authorId);
        $cacheKeys = array_map(
            fn($article) => $this->buildArticleCacheKey($article->getId()),
            $articles
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateAuthorArticleCount($authorId);
        $this->logger->info('Invalidated articles for author', [
            'author_id' => $authorId,
            'article_count' => count($articles),
        ]);
    }

    public function invalidateCategoryArticles(int $categoryId): void
    {
        $articles = $this->articleRepository->findByCategoryId($categoryId);
        $cacheKeys = array_map(
            fn($article) => $this->buildArticleCacheKey($article->getId()),
            $articles
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateCategoryArticleCount($categoryId);
        $this->invalidateCategoryFeaturedArticles($categoryId);
        $this->logger->info('Invalidated articles in category', [
            'category_id' => $categoryId,
            'article_count' => count($articles),
        ]);
    }

    public function refreshArticle(int $articleId): void
    {
        $cacheKey = $this->buildArticleCacheKey($articleId);
        $article = $this->articleRepository->find($articleId);

        if ($article === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeArticle($article);
        $this->setArticle($articleId, $data);
    }

    public function warmAuthor(int $authorId): void
    {
        $articles = $this->articleRepository->findRecentByAuthorId($authorId, 20);

        foreach ($articles as $article) {
            $data = $this->serializeArticle($article);
            $this->setArticle($article->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed article cache for author', [
            'author_id' => $authorId,
            'articles_warmed' => count($articles),
        ]);
    }

    public function handlePublishArticle(int $articleId): void
    {
        $this->invalidateArticle($articleId);

        $article = $this->articleRepository->find($articleId);
        if ($article === null) {
            return;
        }

        $publishKeys = [
            $this->keyBuilder->build('article', $articleId, 'content'),
            $this->keyBuilder->build('article', $articleId, 'seo_data'),
            $this->keyBuilder->build('article', $articleId, 'social_preview'),
        ];

        foreach ($publishKeys as $key) {
            $this->cache->delete($key);
        }

        $this->invalidateAuthorArticles($article->getAuthorId());
        $this->invalidateCategoryArticles($article->getCategoryId());
        $this->invalidateHomepageArticles();

        $this->metrics->increment('cache.invalidation', [
            'type' => 'publish_article',
            'article_id' => (string) $articleId,
        ]);
    }

    public function handleUpdateArticle(int $articleId): void
    {
        $this->invalidateArticle($articleId);

        $article = $this->articleRepository->find($articleId);
        if ($article === null) {
            return;
        }

        $updateKeys = [
            $this->keyBuilder->build('article', $articleId, 'comments'),
            $this->keyBuilder->build('article', $articleId, 'related_articles'),
            $this->keyBuilder->build('article', $articleId, 'share_count'),
        ];

        foreach ($updateKeys as $key) {
            $this->cache->delete($key);
        }

        $this->logger->info('Handled article update cache invalidation', [
            'article_id' => $articleId,
        ]);
    }

    public function handleDeleteArticle(int $articleId): void
    {
        $article = $this->articleRepository->find($articleId);
        if ($article !== null) {
            $this->invalidateArticle($articleId);
            $this->invalidateAuthorArticles($article->getAuthorId());
            $this->invalidateCategoryArticles($article->getCategoryId());
        }

        $this->logger->info('Handled article deletion cache invalidation', [
            'article_id' => $articleId,
        ]);
    }

    public function handleAuthorUpdate(int $authorId): void
    {
        $this->invalidateAuthorArticleCount($authorId);

        $authorKeys = [
            $this->keyBuilder->build('author', $authorId, 'profile'),
            $this->keyBuilder->build('author', $authorId, 'bio'),
            $this->keyBuilder->build('author', $authorId, 'avatar'),
        ];

        foreach ($authorKeys as $key) {
            $this->cache->delete($key);
        }

        $this->invalidateAuthorArticles($authorId);
        $this->metrics->increment('cache.invalidation', [
            'type' => 'author_update',
            'author_id' => (string) $authorId,
        ]);
    }

    public function handleCategoryUpdate(int $categoryId): void
    {
        $this->invalidateCategoryArticleCount($categoryId);
        $this->invalidateCategoryFeaturedArticles($categoryId);

        $categoryKeys = [
            $this->keyBuilder->build('category', $categoryId, 'featured'),
            $this->keyBuilder->build('category', $categoryId, 'metadata'),
        ];

        foreach ($categoryKeys as $key) {
            $this->cache->delete($key);
        }

        $this->invalidateCategoryArticles($categoryId);
        $this->invalidateHomepageArticles();

        $this->logger->info('Handled category update cache invalidation', [
            'category_id' => $categoryId,
        ]);
    }

    private function buildArticleCacheKey(int $articleId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'article', $articleId);
    }

    private function buildAuthorArticleCountCacheKey(int $authorId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'author', $authorId, 'article_count');
    }

    private function buildCategoryArticleCountCacheKey(int $categoryId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'category', $categoryId, 'article_count');
    }

    private function buildCategoryFeaturedArticlesCacheKey(int $categoryId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'category', $categoryId, 'featured_articles');
    }

    private function invalidateAuthorArticleCount(int $authorId): void
    {
        $this->cache->delete($this->buildAuthorArticleCountCacheKey($authorId));
    }

    private function invalidateCategoryArticleCount(int $categoryId): void
    {
        $this->cache->delete($this->buildCategoryArticleCountCacheKey($categoryId));
    }

    private function invalidateCategoryFeaturedArticles(int $categoryId): void
    {
        $this->cache->delete($this->buildCategoryFeaturedArticlesCacheKey($categoryId));
    }

    private function invalidateHomepageArticles(): void
    {
        $homepageKeys = [
            $this->keyBuilder->build('homepage', 'latest_articles'),
            $this->keyBuilder->build('homepage', 'featured_articles'),
            $this->keyBuilder->build('homepage', 'trending_articles'),
        ];

        foreach ($homepageKeys as $key) {
            $this->cache->delete($key);
        }
    }

    private function serializeArticle(object $article): array
    {
        return [
            'id' => $article->getId(),
            'title' => $article->getTitle(),
            'slug' => $article->getSlug(),
            'author_id' => $article->getAuthorId(),
            'category_id' => $article->getCategoryId(),
            'excerpt' => $article->getExcerpt(),
            'published_at' => $article->getPublishedAt()?->format(\DATE_ATOM),
            'status' => $article->getStatus(),
        ];
    }
}
