<?php
declare(strict_types=1);

namespace BatchProcess\Shared;

interface BatchEntityProcessor
{
    public function processSingle(mixed $entity): ProcessResult;
    public function getEntityType(): string;
    public function getRepository(): BatchRepository;
}

abstract class BaseBatchProcessor
{
    protected LoggerInterface $logger;
    protected NotificationService $notificationService;

    private const BATCH_SIZE = 50;
    private const RETRY_DELAY_MS = 1000;

    public function processPending(): BatchProcessResult
    {
        $this->logger->info("Starting {$this->getEntityType()} batch processing");

        $pendingEntities = $this->getRepository()->findPendingForProcessing();

        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $batches = array_chunk($pendingEntities, self::BATCH_SIZE, true);

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $entity) {
                $result = $this->processWithRetry($entity);

                if ($result->isSuccess()) {
                    $results['succeeded']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = $result->getErrorMessage();
                }

                $results['processed']++;
            }
        }

        $this->notificationService->sendBatchCompletionNotice($results);

        return new BatchProcessResult(
            entityType: $this->getEntityType(),
            processedCount: $results['processed'],
            successCount: $results['succeeded'],
            failureCount: $results['failed'],
            errors: $results['errors'],
        );
    }

    private function processWithRetry(mixed $entity): ProcessResult
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                $result = $this->processSingle($entity);

                if ($result->isSuccess()) {
                    return $result;
                }

                throw new \RuntimeException($result->getErrorMessage() ?? 'Unknown error');

            } catch (\Throwable $e) {
                $attempts++;

                if ($attempts >= $maxAttempts) {
                    return ProcessResult::failure($e->getMessage());
                }

                usleep(self::RETRY_DELAY_MS * 1000);
            }
        }

        return ProcessResult::failure('Max attempts exceeded');
    }

    abstract protected function getEntityType(): string;
    abstract protected function getRepository(): BatchRepository;
}

final class InvoiceBatchProcessor extends BaseBatchProcessor
{
    private PaymentGateway $paymentGateway;

    public function __construct(
        LoggerInterface $logger,
        NotificationService $notificationService,
        InvoiceRepository $invoiceRepository,
        PaymentGateway $paymentGateway,
    ) {
        $this->logger = $logger;
        $this->notificationService = $notificationService;
        $this->paymentGateway = $paymentGateway;
    }

    protected function getEntityType(): string
    {
        return 'invoice';
    }

    protected function getRepository(): BatchRepository
    {
        return $this->invoiceRepository;
    }

    public function processSingle(mixed $invoice): ProcessResult
    {
        $paymentResult = $this->paymentGateway->charge($invoice);

        if ($paymentResult->isSuccessful()) {
            $invoice->markAsPaid($paymentResult->getTransactionId());
            return ProcessResult::success();
        }

        return ProcessResult::failure('Payment declined');
    }
}
