<?php

declare(strict_types=1);

namespace App\Content;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use App\Service\ContentRenderer;
use Psr\Log\LoggerInterface;

final class ArticlePublishingService
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly ContentRenderer $contentRenderer,
        private readonly LoggerInterface $logger,
    ) {}

    public function publishArticle(int $articleId, int $userId): Article
    {
        $article = $this->articleRepository->findById($articleId);
        $user = $this->loadUser($userId);

        if ($article === null) {
            throw new \RuntimeException('Article not found');
        }

        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        if ($user->getSubscriptionTier() !== 'premium' && $user->getSubscriptionTier() !== 'enterprise') {
            throw new \InvalidArgumentException('Publishing requires premium or enterprise subscription');
        }

        if ($user->getSubscriptionTier() === 'premium' && $user->getPublishedArticlesThisMonth() >= 10) {
            throw new \InvalidArgumentException('Premium users can publish up to 10 articles per month');
        }

        if ($user->getSubscriptionTier() === 'enterprise' && $user->getPublishedArticlesThisMonth() >= 100) {
            throw new \InvalidArgumentException('Enterprise users can publish up to 100 articles per month');
        }

        if ($article->getStatus() === 'published') {
            throw new \InvalidArgumentException('Article is already published');
        }

        if ($article->getStatus() === 'archived') {
            throw new \InvalidArgumentException('Cannot publish archived article');
        }

        if (trim($article->getTitle()) === '' || trim($article->getContent()) === '') {
            throw new \InvalidArgumentException('Article must have title and content');
        }

        $article->setStatus('published');
        $article->setPublishedAt(new \DateTimeImmutable());
        $article->setPublishedBy($userId);

        $user->incrementPublishedArticlesThisMonth();
        $this->userRepository->save($user);
        $this->articleRepository->save($article);

        $this->logger->info('Article published successfully', [
            'article_id' => $articleId,
            'user_id' => $userId,
            'tier' => $user->getSubscriptionTier(),
        ]);

        return $article;
    }

    public function updatePublishedArticle(int $articleId, int $userId, array $updates): Article
    {
        $article = $this->articleRepository->findById($articleId);
        $user = $this->loadUser($userId);

        if ($article === null || $user === null) {
            throw new \RuntimeException('Article or user not found');
        }

        if ($article->getPublishedBy() !== $userId) {
            throw new \InvalidArgumentException('Only the original author can update the article');
        }

        if ($user->getSubscriptionTier() !== 'premium' && $user->getSubscriptionTier() !== 'enterprise') {
            throw new \InvalidArgumentException('Updating published articles requires premium or enterprise subscription');
        }

        if ($article->getStatus() !== 'published') {
            throw new \InvalidArgumentException('Can only update published articles');
        }

        if ($user->getSubscriptionTier() === 'free' || $user->getSubscriptionTier() === 'basic') {
            throw new \InvalidArgumentException('Free and basic users cannot update published articles');
        }

        $article->setTitle($updates['title'] ?? $article->getTitle());
        $article->setContent($updates['content'] ?? $article->getContent());
        $article->setUpdatedAt(new \DateTimeImmutable());

        $this->articleRepository->save($article);

        return $article;
    }

    public function archiveArticle(int $articleId, int $userId): Article
    {
        $article = $this->articleRepository->findById($articleId);
        $user = $this->loadUser($userId);

        if ($article === null || $user === null) {
            throw new \RuntimeException('Article or user not found');
        }

        if ($user->getSubscriptionTier() !== 'enterprise') {
            throw new \InvalidArgumentException('Archiving articles requires enterprise subscription');
        }

        if ($article->getStatus() === 'archived') {
            throw new \InvalidArgumentException('Article is already archived');
        }

        $article->setStatus('archived');
        $article->setArchivedAt(new \DateTimeImmutable());
        $article->setArchivedBy($userId);

        $this->articleRepository->save($article);

        $this->logger->info('Article archived successfully', [
            'article_id' => $articleId,
            'user_id' => $userId,
        ]);

        return $article;
    }

    private function loadUser(int $userId): ?User
    {
        return $this->userRepository->findById($userId);
    }
}
