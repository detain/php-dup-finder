<?php
declare(strict_types=1);

namespace App\Product\Publishing;

use App\Domain\Entity\Product;
use App\Domain\Repository\ProductRepositoryInterface;
use App\Domain\Service\ContentValidationServiceInterface;
use App\Domain\Service\MediaServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use App\Domain\Service\SeoServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class ProductPublishingWorkflow
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private ContentValidationServiceInterface $contentValidation,
        private MediaServiceInterface $mediaService,
        private NotificationServiceInterface $notificationService,
        private SeoServiceInterface $seoService,
        private LoggerInterface $logger,
    ) {}

    public function publishProduct(string $productId): void
    {
        $product = $this->productRepository->findById($productId);
        if ($product === null) {
            throw new \RuntimeException("Product not found: {$productId}");
        }

        $this->logger->info('Starting product publishing workflow', ['product_id' => $productId]);

        $this->validateContent($product);

        $this->processMedia($product);

        $this->optimizeSeo($product);

        $this->approveContent($product);

        $this->schedulePublication($product);

        $this->notifySubscribers($product);

        $this->updateProductStatus($product, 'published');

        $this->recordAuditEvent($product, 'product_published');

        $this->logger->info('Product publishing workflow completed', ['product_id' => $productId]);
    }

    private function validateContent(Product $product): void
    {
        $result = $this->contentValidation->validate($product);
        if (!$result->isValid()) {
            $this->recordAuditEvent($product, 'content_validation_failed', [
                'errors' => $result->getErrors(),
            ]);
            throw new \RuntimeException("Content validation failed: " . implode(', ', $result->getErrors()));
        }

        if (trim($product->getName()) === '') {
            throw new \RuntimeException("Product name cannot be empty");
        }

        if (trim($product->getDescription()) === '') {
            throw new \RuntimeException("Product description cannot be empty");
        }

        if ($product->getPrice() === null) {
            throw new \RuntimeException("Product must have a price");
        }

        $this->recordAuditEvent($product, 'content_validated');
        $this->logger->debug('Product content validation passed', ['product_id' => $product->getId()->toString()]);
    }

    private function processMedia(Product $product): void
    {
        $images = $product->getImages();
        foreach ($images as $image) {
            $processed = $this->mediaService->processImage($image, [
                'max_width' => 1200,
                'max_height' => 1200,
                'formats' => ['webp', 'jpg'],
            ]);

            if (!$processed->isSuccessful()) {
                $this->recordAuditEvent($product, 'media_processing_failed', [
                    'error' => $processed->getError(),
                ]);
                throw new \RuntimeException("Media processing failed: {$processed->getError()}");
            }
        }

        foreach ($product->getVideos() as $video) {
            $this->mediaService->processVideo($video, [
                'max_duration' => 300,
                'formats' => ['mp4', 'webm'],
            ]);
        }

        $this->recordAuditEvent($product, 'media_processed');
        $this->logger->debug('Product media processed', ['product_id' => $product->getId()->toString()]);
    }

    private function optimizeSeo(Product $product): void
    {
        $seoResult = $this->seoService->optimize($product, [
            'target_keyword' => $product->getPrimaryKeyword(),
            'title_length' => 60,
            'meta_description_length' => 160,
        ]);

        $product->setSeoTitle($seoResult->getTitle());
        $product->setSeoDescription($seoResult->getDescription());
        $product->setSeoKeywords($seoResult->getKeywords());

        if (!$seoResult->isKeywordFound()) {
            $this->logger->warning('Primary keyword not found in content', [
                'product_id' => $product->getId()->toString(),
                'keyword' => $product->getPrimaryKeyword(),
            ]);
        }

        $this->recordAuditEvent($product, 'seo_optimized');
        $this->logger->debug('Product SEO optimized', ['product_id' => $product->getId()->toString()]);
    }

    private function approveContent(Product $product): void
    {
        $product->setApproved(true);
        $product->setApprovedAt(new \DateTimeImmutable());
        $this->productRepository->save($product);

        $this->recordAuditEvent($product, 'content_approved');
        $this->logger->debug('Product approved', ['product_id' => $product->getId()->toString()]);
    }

    private function schedulePublication(Product $product): void
    {
        if ($product->getScheduledPublishAt() === null) {
            $product->setPublishedAt(new \DateTimeImmutable());
            $product->setStatus('published');
        } else {
            $product->setStatus('scheduled');
        }

        $this->productRepository->save($product);

        $this->recordAuditEvent($product, 'publication_scheduled', [
            'scheduled_for' => $product->getScheduledPublishAt()?->format(\DateTimeInterface::ATOM),
        ]);
        $this->logger->debug('Product publication scheduled', ['product_id' => $product->getId()->toString()]);
    }

    private function notifySubscribers(Product $product): void
    {
        $subscribers = $product->getCategory()->getSubscribers();
        foreach (array_slice($subscribers, 0, 1000) as $subscriber) {
            $this->notificationService->send(
                $subscriber->getUserId(),
                'new_product',
                [
                    'product_id' => $product->getId()->toString(),
                    'name' => $product->getName(),
                    'price' => $product->getPrice()->getAmount(),
                    'url' => $product->getUrl(),
                ]
            );
        }

        $this->recordAuditEvent($product, 'subscribers_notified', [
            'count' => min(count($subscribers), 1000),
        ]);
        $this->logger->debug('Subscribers notified', ['product_id' => $product->getId()->toString()]);
    }

    private function updateProductStatus(Product $product, string $status): void
    {
        $product->setStatus($status);
        $product->setUpdatedAt(new \DateTimeImmutable());
        $this->productRepository->save($product);
    }

    private function recordAuditEvent(Product $product, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'product_id' => $product->getId()->toString(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
