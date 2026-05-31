<?php
declare(strict_types=1);

namespace Queues\Workers;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

final class ImageProcessingWorker
{
    private const FALLBACK_QUEUE = 'image_processing_fallback';
    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly LoggerInterface $logger,
        private readonly ImageProcessor $processor,
        private readonly StorageService $storage
    ) {}

    public function process(AMQPMessage $message): void
    {
        $body = json_decode($message->getBody(), true);
        $imageId = $body['image_id'] ?? null;

        if ($imageId === null) {
            $this->logger->error('Invalid message: missing image_id');
            return;
        }

        $attempt = $body['_attempt'] ?? 0;

        try {
            $image = $this->entityManager->find(Image::class, $imageId);

            if ($image === null) {
                throw new \RuntimeException("Image not found: {$imageId}");
            }

            // Process the image
            $result = $this->processor->process($image);

            // Store processed image
            $url = $this->storage->upload($result['path'], 'images/processed');

            // Update database
            $image->setProcessedUrl($url);
            $image->setProcessedAt(new \DateTimeImmutable());
            $image->setStatus('completed');
            $this->entityManager->flush();

            $this->logger->info('Image processed successfully', [
                'image_id' => $imageId,
                'url' => $url
            ]);

        } catch (\Exception $e) {
            $this->handleProcessingFailure($message, $imageId, $attempt, $e);
        }
    }

    private function handleProcessingFailure(
        AMQPMessage $message,
        ?int $imageId,
        int $attempt,
        \Exception $e
    ): void {
        if ($imageId !== null) {
            $image = $this->entityManager->find(Image::class, $imageId);
        }

        if ($attempt < self::MAX_RETRIES) {
            // Requeue with incremented attempt counter
            $body = json_decode($message->getBody(), true);
            $body['_attempt'] = $attempt + 1;

            $retryDelay = (2 ** $attempt) * 1000; // Exponential backoff

            $this->logger->warning('Image processing failed, requeueing', [
                'image_id' => $imageId,
                'attempt' => $attempt + 1,
                'delay_ms' => $retryDelay,
                'error' => $e->getMessage()
            ]);

            // Schedule retry via fallback queue
            $this->scheduleRetry(json_encode($body), $retryDelay);

        } else {
            // Max retries exceeded - move to dead letter queue
            $this->logger->error('Image processing failed after max retries', [
                'image_id' => $imageId,
                'attempts' => $attempt,
                'error' => $e->getMessage()
            ]);

            if (isset($image)) {
                $image->setStatus('failed');
                $image->setFailureReason($e->getMessage());
                $this->entityManager->flush();
            }

            // Move to dead letter queue for manual inspection
            $this->moveToDeadLetterQueue($message, $e);
        }
    }

    private function scheduleRetry(string $messageBody, int $delayMs): void
    {
        try {
            // Use delayed message exchange or fallback to scheduled polling
            $this->entityManager->getRepository(RetryJob::class)->save(
                (new RetryJob())
                    ->setPayload($messageBody)
                    ->setQueue(self::FALLBACK_QUEUE)
                    ->setAvailableAt((new \DateTimeImmutable())->modify("+{$delayMs} milliseconds"))
            );
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->logger->error('Failed to schedule retry', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function moveToDeadLetterQueue(AMQPMessage $message, \Exception $e): void
    {
        try {
            $dlqMessage = json_encode([
                'original_message' => $message->getBody(),
                'error' => $e->getMessage(),
                'failed_at' => date('c')
            ]);

            // Store in database dead letter table
            $this->entityManager->getRepository(DeadLetterJob::class)->save(
                (new DeadLetterJob())
                    ->setQueue('image_processing')
                    ->setPayload($message->getBody())
                    ->setError($e->getMessage())
                    ->setFailedAt(new \DateTimeImmutable())
            );
            $this->entityManager->flush();

            $this->logger->info('Message moved to dead letter queue', [
                'queue' => 'image_processing',
                'error' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            $this->logger->critical('Failed to move message to dead letter queue', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
