<?php
declare(strict_types=1);

namespace BatchProcess\Workflow;

use Psr\Log\LoggerInterface;

final class RefundBatchProcessor
{
    private const BATCH_SIZE = 50;
    private const RETRY_DELAY_MS = 1000;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RefundRepository $refundRepository,
        private readonly PaymentGateway $paymentGateway,
        private readonly NotificationService $notificationService,
    ) {}

    public function processPendingRefunds(): BatchProcessResult
    {
        $this->logger->info('Starting refund batch processing');

        $pendingRefunds = $this->refundRepository->findPendingForProcessing();
        $this->logger->debug('Found pending refunds', ['count' => count($pendingRefunds)]);

        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $batches = array_chunk($pendingRefunds, self::BATCH_SIZE, true);

        foreach ($batches as $batchIndex => $batch) {
            $this->logger->debug('Processing batch', [
                'batch' => $batchIndex + 1,
                'size' => count($batch),
            ]);

            foreach ($batch as $refund) {
                $result = $this->processSingleRefund($refund);

                if ($result->isSuccess()) {
                    $results['succeeded']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = $result->getErrorMessage();
                }

                $results['processed']++;
            }

            $this->logger->debug('Batch completed', [
                'batch' => $batchIndex + 1,
                'succeeded' => $results['succeeded'],
                'failed' => $results['failed'],
            ]);
        }

        $this->notificationService->sendBatchCompletionNotice($results);

        $this->logger->info('Refund batch processing completed', [
            'processed' => $results['processed'],
            'succeeded' => $results['succeeded'],
            'failed' => $results['failed'],
        ]);

        return new BatchProcessResult(
            entityType: 'refund',
            processedCount: $results['processed'],
            successCount: $results['succeeded'],
            failureCount: $results['failed'],
            errors: $results['errors'],
        );
    }

    private function processSingleRefund(Refund $refund): ProcessResult
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                $this->logger->debug('Processing refund', [
                    'refund_id' => $refund->getId(),
                    'attempt' => $attempts + 1,
                ]);

                $refundResult = $this->paymentGateway->refund($refund);

                if ($refundResult->isSuccessful()) {
                    $refund->markAsProcessed($refundResult->getTransactionId());
                    $this->refundRepository->save($refund);

                    $this->notificationService->sendRefundConfirmation($refund);

                    return ProcessResult::success();
                }

                throw new \RuntimeException('Refund failed: ' . $refundResult->getDeclineReason());

            } catch (\Throwable $e) {
                $attempts++;

                $this->logger->warning('Refund processing attempt failed', [
                    'refund_id' => $refund->getId(),
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts >= $maxAttempts) {
                    $refund->markAsFailed($e->getMessage());
                    $this->refundRepository->save($refund);

                    $this->notificationService->sendRefundFailedNotice($refund, $e->getMessage());

                    return ProcessResult::failure($e->getMessage());
                }

                usleep(self::RETRY_DELAY_MS * 1000);
            }
        }

        return ProcessResult::failure('Max attempts exceeded');
    }
}
