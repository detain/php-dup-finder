<?php
declare(strict_types=1);

namespace App\Content\Publishing;

use App\Domain\Entity\Article;
use App\Domain\Repository\ArticleRepositoryInterface;
use App\Domain\Service\ContentValidationServiceInterface;
use App\Domain\Service\MediaServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use App\Domain\Service\SeoServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class ArticlePublishingWorkflow
{
    public function __construct(
        private ArticleRepositoryInterface $articleRepository,
        private ContentValidationServiceInterface $contentValidation,
        private MediaServiceInterface $mediaService,
        private NotificationServiceInterface $notificationService,
        private SeoServiceInterface $seoService,
        private LoggerInterface $logger,
    ) {}

    public function publishArticle(string $articleId): void
    {
        $article = $this->articleRepository->findById($articleId);
        if ($article === null) {
            throw new \RuntimeException("Article not found: {$articleId}");
        }

        $this->logger->info('Starting article publishing workflow', ['article_id' => $articleId]);

        $this->validateContent($article);

        $this->processMedia($article);

        $this->optimizeSeo($article);

        $this->approveContent($article);

        $this->schedulePublication($article);

        $this->notifySubscribers($article);

        $this->updateArticleStatus($article, 'published');

        $this->recordAuditEvent($article, 'article_published');

        $this->logger->info('Article publishing workflow completed', ['article_id' => $articleId]);
    }

    private function validateContent(Article $article): void
    {
        $result = $this->contentValidation->validate($article);
        if (!$result->isValid()) {
            $this->recordAuditEvent($article, 'content_validation_failed', [
                'errors' => $result->getErrors(),
            ]);
            throw new \RuntimeException("Content validation failed: " . implode(', ', $result->getErrors()));
        }

        if (trim($article->getTitle()) === '') {
            throw new \RuntimeException("Article title cannot be empty");
        }

        if (trim($article->getBody()) === '') {
            throw new \RuntimeException("Article body cannot be empty");
        }

        if ($article->getAuthor() === null) {
            throw new \RuntimeException("Article must have an author");
        }

        $this->recordAuditEvent($article, 'content_validated');
        $this->logger->debug('Article content validation passed', ['article_id' => $article->getId()->toString()]);
    }

    private function processMedia(Article $article): void
    {
        $featuredImage = $article->getFeaturedImage();
        if ($featuredImage !== null) {
            $processed = $this->mediaService->processImage($featuredImage, [
                'max_width' => 1200,
                'max_height' => 800,
                'formats' => ['webp', 'jpg'],
            ]);

            if (!$processed->isSuccessful()) {
                $this->recordAuditEvent($article, 'media_processing_failed', [
                    'error' => $processed->getError(),
                ]);
                throw new \RuntimeException("Media processing failed: {$processed->getError()}");
            }

            $article->setFeaturedImageUrl($processed->getUrl());
        }

        foreach ($article->getEmbeddedMedia() as $media) {
            $this->mediaService->validateMedia($media);
        }

        $this->recordAuditEvent($article, 'media_processed');
        $this->logger->debug('Article media processed', ['article_id' => $article->getId()->toString()]);
    }

    private function optimizeSeo(Article $article): void
    {
        $seoResult = $this->seoService->optimize($article, [
            'target_keyword' => $article->getPrimaryKeyword(),
            'title_length' => 60,
            'meta_description_length' => 160,
        ]);

        $article->setSeoTitle($seoResult->getTitle());
        $article->setSeoDescription($seoResult->getDescription());
        $article->setSeoKeywords($seoResult->getKeywords());

        if (!$seoResult->isKeywordFound()) {
            $this->logger->warning('Primary keyword not found in content', [
                'article_id' => $article->getId()->toString(),
                'keyword' => $article->getPrimaryKeyword(),
            ]);
        }

        $this->recordAuditEvent($article, 'seo_optimized');
        $this->logger->debug('Article SEO optimized', ['article_id' => $article->getId()->toString()]);
    }

    private function approveContent(Article $article): void
    {
        $article->setApproved(true);
        $article->setApprovedAt(new \DateTimeImmutable());
        $this->articleRepository->save($article);

        $this->recordAuditEvent($article, 'content_approved');
        $this->logger->debug('Article approved', ['article_id' => $article->getId()->toString()]);
    }

    private function schedulePublication(Article $article): void
    {
        if ($article->getScheduledPublishAt() === null) {
            $article->setPublishedAt(new \DateTimeImmutable());
            $article->setStatus('published');
        } else {
            $article->setStatus('scheduled');
        }

        $this->articleRepository->save($article);

        $this->recordAuditEvent($article, 'publication_scheduled', [
            'scheduled_for' => $article->getScheduledPublishAt()?->format(\DateTimeInterface::ATOM),
        ]);
        $this->logger->debug('Article publication scheduled', ['article_id' => $article->getId()->toString()]);
    }

    private function notifySubscribers(Article $article): void
    {
        $subscribers = $article->getCategory()->getSubscribers();
        foreach (array_slice($subscribers, 0, 1000) as $subscriber) {
            $this->notificationService->send(
                $subscriber->getUserId(),
                'new_article',
                [
                    'article_id' => $article->getId()->toString(),
                    'title' => $article->getTitle(),
                    'excerpt' => $article->getExcerpt(),
                    'url' => $article->getUrl(),
                ]
            );
        }

        $this->recordAuditEvent($article, 'subscribers_notified', [
            'count' => min(count($subscribers), 1000),
        ]);
        $this->logger->debug('Subscribers notified', ['article_id' => $article->getId()->toString()]);
    }

    private function updateArticleStatus(Article $article, string $status): void
    {
        $article->setStatus($status);
        $article->setUpdatedAt(new \DateTimeImmutable());
        $this->articleRepository->save($article);
    }

    private function recordAuditEvent(Article $article, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'article_id' => $article->getId()->toString(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
