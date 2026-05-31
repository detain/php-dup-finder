<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\CommentRepository;
use App\Repository\ArticleRepository;
use App\Repository\UserRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class CommentCacheHandler
{
    private const CACHE_PREFIX = 'comment';
    private const DEFAULT_TTL = 1800;
    private const STALE_TTL = 300;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly CommentRepository $commentRepository,
        private readonly ArticleRepository $articleRepository,
        private readonly UserRepository $userRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getComment(int $commentId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildCommentCacheKey($commentId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'comment']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'comment']);
        $comment = $this->commentRepository->find($commentId);

        if ($comment === null) {
            return null;
        }

        $data = $this->serializeComment($comment);
        $this->setComment($commentId, $data);
        return $data;
    }

    public function setComment(int $commentId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildCommentCacheKey($commentId);
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidateComment(int $commentId): void
    {
        $cacheKey = $this->buildCommentCacheKey($commentId);
        $this->cache->delete($cacheKey);
    }

    public function invalidateArticleComments(int $articleId): void
    {
        $comments = $this->commentRepository->findByArticleId($articleId);
        $cacheKeys = array_map(
            fn($comment) => $this->buildCommentCacheKey($comment->getId()),
            $comments
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateArticleCommentCount($articleId);
        $this->logger->info('Invalidated comments for article', [
            'article_id' => $articleId,
            'comment_count' => count($comments),
        ]);
    }

    public function invalidateUserComments(int $userId): void
    {
        $comments = $this->commentRepository->findByUserId($userId);
        $cacheKeys = array_map(
            fn($comment) => $this->buildCommentCacheKey($comment->getId()),
            $comments
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateUserCommentCount($userId);
        $this->logger->info('Invalidated comments for user', [
            'user_id' => $userId,
            'comment_count' => count($comments),
        ]);
    }

    public function refreshComment(int $commentId): void
    {
        $cacheKey = $this->buildCommentCacheKey($commentId);
        $comment = $this->commentRepository->find($commentId);

        if ($comment === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeComment($comment);
        $this->setComment($commentId, $data);
    }

    public function warmArticle(int $articleId): void
    {
        $comments = $this->commentRepository->findRecentByArticleId($articleId, 50);

        foreach ($comments as $comment) {
            $data = $this->serializeComment($comment);
            $this->setComment($comment->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed comment cache for article', [
            'article_id' => $articleId,
            'comments_warmed' => count($comments),
        ]);
    }

    public function handleCreateComment(int $commentId): void
    {
        $this->invalidateComment($commentId);

        $comment = $this->commentRepository->find($commentId);
        if ($comment === null) {
            return;
        }

        $this->invalidateArticleComments($comment->getArticleId());
        $this->invalidateUserComments($comment->getUserId());

        $this->metrics->increment('cache.invalidation', [
            'type' => 'create_comment',
            'comment_id' => (string) $commentId,
        ]);
    }

    public function handleUpdateComment(int $commentId): void
    {
        $this->invalidateComment($commentId);

        $comment = $this->commentRepository->find($commentId);
        if ($comment === null) {
            return;
        }

        $updateKeys = [
            $this->keyBuilder->build('comment', $commentId, 'replies'),
            $this->keyBuilder->build('comment', $commentId, 'votes'),
            $this->keyBuilder->build('comment', $commentId, 'moderation_status'),
        ];

        foreach ($updateKeys as $key) {
            $this->cache->delete($key);
        }

        $this->logger->info('Handled comment update cache invalidation', [
            'comment_id' => $commentId,
        ]);
    }

    public function handleDeleteComment(int $commentId): void
    {
        $comment = $this->commentRepository->find($commentId);
        if ($comment !== null) {
            $this->invalidateComment($commentId);
            $this->invalidateArticleComments($comment->getArticleId());
            $this->invalidateUserComments($comment->getUserId());
        }

        $this->logger->info('Handled comment deletion cache invalidation', [
            'comment_id' => $commentId,
        ]);
    }

    public function handleModerationAction(int $commentId): void
    {
        $this->invalidateComment($commentId);

        $moderationKeys = [
            $this->keyBuilder->build('comment', $commentId, 'moderation_history'),
            $this->keyBuilder->build('comment', $commentId, 'flags'),
        ];

        foreach ($moderationKeys as $key) {
            $this->cache->delete($key);
        }

        $comment = $this->commentRepository->find($commentId);
        if ($comment !== null) {
            $this->invalidateArticleComments($comment->getArticleId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'moderation_action',
            'comment_id' => (string) $commentId,
        ]);
    }

    private function buildCommentCacheKey(int $commentId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'comment', $commentId);
    }

    private function buildArticleCommentCountCacheKey(int $articleId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'article', $articleId, 'comment_count');
    }

    private function buildUserCommentCountCacheKey(int $userId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'user', $userId, 'comment_count');
    }

    private function invalidateArticleCommentCount(int $articleId): void
    {
        $this->cache->delete($this->buildArticleCommentCountCacheKey($articleId));
    }

    private function invalidateUserCommentCount(int $userId): void
    {
        $this->cache->delete($this->buildUserCommentCountCacheKey($userId));
    }

    private function serializeComment(object $comment): array
    {
        return [
            'id' => $comment->getId(),
            'article_id' => $comment->getArticleId(),
            'user_id' => $comment->getUserId(),
            'content' => $comment->getContent(),
            'parent_id' => $comment->getParentId(),
            'status' => $comment->getStatus(),
            'created_at' => $comment->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }
}
