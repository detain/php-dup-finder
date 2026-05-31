<?php
declare(strict_types=1);

namespace App\Media\Publishing;

use App\Domain\Entity\Media;
use App\Domain\Repository\MediaRepositoryInterface;
use App\Domain\Service\ContentValidationServiceInterface;
use App\Domain\Service\MediaServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use App\Domain\Service\SeoServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class MediaPublishingWorkflow
{
    public function __construct(
        private MediaRepositoryInterface $mediaRepository,
        private ContentValidationServiceInterface $contentValidation,
        private MediaServiceInterface $mediaService,
        private NotificationServiceInterface $notificationService,
        private SeoServiceInterface $seoService,
        private LoggerInterface $logger,
    ) {}

    public function publishMedia(string $mediaId): void
    {
        $media = $this->mediaRepository->findById($mediaId);
        if ($media === null) {
            throw new \RuntimeException("Media not found: {$mediaId}");
        }

        $this->logger->info('Starting media publishing workflow', ['media_id' => $mediaId]);

        $this->validateContent($media);

        $this->processMedia($media);

        $this->optimizeSeo($media);

        $this->approveContent($media);

        $this->schedulePublication($media);

        $this->notifySubscribers($media);

        $this->updateMediaStatus($media, 'published');

        $this->recordAuditEvent($media, 'media_published');

        $this->logger->info('Media publishing workflow completed', ['media_id' => $mediaId]);
    }

    private function validateContent(Media $media): void
    {
        $result = $this->contentValidation->validate($media);
        if (!$result->isValid()) {
            $this->recordAuditEvent($media, 'content_validation_failed', [
                'errors' => $result->getErrors(),
            ]);
            throw new \RuntimeException("Content validation failed: " . implode(', ', $result->getErrors()));
        }

        if (trim($media->getTitle()) === '') {
            throw new \RuntimeException("Media title cannot be empty");
        }

        if ($media->getFile() === null) {
            throw new \RuntimeException("Media must have a file");
        }

        if ($media->getUploadedBy() === null) {
            throw new \RuntimeException("Media must have uploader");
        }

        $this->recordAuditEvent($media, 'content_validated');
        $this->logger->debug('Media content validation passed', ['media_id' => $media->getId()->toString()]);
    }

    private function processMedia(Media $media): void
    {
        $processed = $this->mediaService->processMedia($media, [
            'max_width' => 1920,
            'max_height' => 1080,
            'formats' => ['webm', 'mp4'],
            'generate_thumbnail' => true,
        ]);

        if (!$processed->isSuccessful()) {
            $this->recordAuditEvent($media, 'media_processing_failed', [
                'error' => $processed->getError(),
            ]);
            throw new \RuntimeException("Media processing failed: {$processed->getError()}");
        }

        $media->setProcessedUrl($processed->getUrl());
        $media->setThumbnailUrl($processed->getThumbnailUrl());
        $media->setDuration($processed->getDuration());

        $this->recordAuditEvent($media, 'media_processed');
        $this->logger->debug('Media processed', ['media_id' => $media->getId()->toString()]);
    }

    private function optimizeSeo(Media $media): void
    {
        $seoResult = $this->seoService->optimize($media, [
            'target_keyword' => $media->getPrimaryKeyword(),
            'title_length' => 60,
            'meta_description_length' => 160,
        ]);

        $media->setSeoTitle($seoResult->getTitle());
        $media->setSeoDescription($seoResult->getDescription());
        $media->setSeoKeywords($seoResult->getKeywords());

        $this->recordAuditEvent($media, 'seo_optimized');
        $this->logger->debug('Media SEO optimized', ['media_id' => $media->getId()->toString()]);
    }

    private function approveContent(Media $media): void
    {
        $media->setApproved(true);
        $media->setApprovedAt(new \DateTimeImmutable());
        $this->mediaRepository->save($media);

        $this->recordAuditEvent($media, 'content_approved');
        $this->logger->debug('Media approved', ['media_id' => $media->getId()->toString()]);
    }

    private function schedulePublication(Media $media): void
    {
        if ($media->getScheduledPublishAt() === null) {
            $media->setPublishedAt(new \DateTimeImmutable());
            $media->setStatus('published');
        } else {
            $media->setStatus('scheduled');
        }

        $this->mediaRepository->save($media);

        $this->recordAuditEvent($media, 'publication_scheduled', [
            'scheduled_for' => $media->getScheduledPublishAt()?->format(\DateTimeInterface::ATOM),
        ]);
        $this->logger->debug('Media publication scheduled', ['media_id' => $media->getId()->toString()]);
    }

    private function notifySubscribers(Media $media): void
    {
        $subscribers = $media->getCollection()->getSubscribers();
        foreach (array_slice($subscribers, 0, 1000) as $subscriber) {
            $this->notificationService->send(
                $subscriber->getUserId(),
                'new_media',
                [
                    'media_id' => $media->getId()->toString(),
                    'title' => $media->getTitle(),
                    'thumbnail_url' => $media->getThumbnailUrl(),
                    'url' => $media->getUrl(),
                ]
            );
        }

        $this->recordAuditEvent($media, 'subscribers_notified', [
            'count' => min(count($subscribers), 1000),
        ]);
        $this->logger->debug('Subscribers notified', ['media_id' => $media->getId()->toString()]);
    }

    private function updateMediaStatus(Media $media, string $status): void
    {
        $media->setStatus($status);
        $media->setUpdatedAt(new \DateTimeImmutable());
        $this->mediaRepository->save($media);
    }

    private function recordAuditEvent(Media $media, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'media_id' => $media->getId()->toString(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
