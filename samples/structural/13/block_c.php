<?php
declare(strict_types=1);

namespace BatchProcess\Workflow;

use Psr\Log\LoggerInterface;

final class SubscriptionBatchProcessor
{
    private const BATCH_SIZE = 50;
    private const RETRY_DELAY_MS = 1000;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly BillingService $billingService,
        private readonly NotificationService $notificationService,
    ) {}

    public function processDueSubscriptions(): BatchProcessResult
    {
        $this->logger->info('Starting subscription batch processing');

        $dueSubscriptions = $this->subscriptionRepository->findDueForRenewal();
        $this->logger->debug('Found due subscriptions', ['count' => count($dueSubscriptions)]);

        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $batches = array_chunk($dueSubscriptions, self::BATCH_SIZE, true);

        foreach ($batches as $batchIndex => $batch) {
            $this->logger->debug('Processing batch', [
                'batch' => $batchIndex + 1,
                'size' => count($batch),
            ]);

            foreach ($batch as $subscription) {
                $result = $this->processSingleSubscription($subscription);

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

        $this->logger->info('Subscription batch processing completed', [
            'processed' => $results['processed'],
            'succeeded' => $results['succeeded'],
            'failed' => $results['failed'],
        ]);

        return new BatchProcessResult(
            entityType: 'subscription',
            processedCount: $results['processed'],
            successCount: $results['succeeded'],
            failureCount: $results['failed'],
            errors: $results['errors'],
        );
    }

    private function processSingleSubscription(Subscription $subscription): ProcessResult
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                $this->logger->debug('Processing subscription', [
                    'subscription_id' => $subscription->getId(),
                    'attempt' => $attempts + 1,
                ]);

                $renewalResult = $this->billingService->renewSubscription($subscription);

                if ($renewalResult->isSuccessful()) {
                    $subscription->markAsRenewed($renewalResult->getNewExpiryDate());
                    $this->subscriptionRepository->save($subscription);

                    $this->notificationService->sendRenewalConfirmation($subscription);

                    return ProcessResult::success();
                }

                throw new \RuntimeException('Renewal failed: ' . $renewalResult->getDeclineReason());

            } catch (\Throwable $e) {
                $attempts++;

                $this->logger->warning('Subscription processing attempt failed', [
                    'subscription_id' => $subscription->getId(),
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts >= $maxAttempts) {
                    $subscription->markAsExpired($e->getMessage());
                    $this->subscriptionRepository->save($subscription);

                    $this->notificationService->sendExpirationNotice($subscription, $e->getMessage());

                    return ProcessResult::failure($e->getMessage());
                }

                usleep(self::RETRY_DELAY_MS * 1000);
            }
        }

        return ProcessResult::failure('Max attempts exceeded');
    }
}
