<?php
declare(strict_types=1);

namespace BatchProcess\Workflow;

use Psr\Log\LoggerInterface;

final class InvoiceBatchProcessor
{
    private const BATCH_SIZE = 50;
    private const RETRY_DELAY_MS = 1000;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PaymentGateway $paymentGateway,
        private readonly NotificationService $notificationService,
    ) {}

    public function processPendingInvoices(): BatchProcessResult
    {
        $this->logger->info('Starting invoice batch processing');

        $pendingInvoices = $this->invoiceRepository->findPendingForProcessing();
        $this->logger->debug('Found pending invoices', ['count' => count($pendingInvoices)]);

        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $batches = array_chunk($pendingInvoices, self::BATCH_SIZE, true);

        foreach ($batches as $batchIndex => $batch) {
            $this->logger->debug('Processing batch', [
                'batch' => $batchIndex + 1,
                'size' => count($batch),
            ]);

            foreach ($batch as $invoice) {
                $result = $this->processSingleInvoice($invoice);

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

        $this->logger->info('Invoice batch processing completed', [
            'processed' => $results['processed'],
            'succeeded' => $results['succeeded'],
            'failed' => $results['failed'],
        ]);

        return new BatchProcessResult(
            entityType: 'invoice',
            processedCount: $results['processed'],
            successCount: $results['succeeded'],
            failureCount: $results['failed'],
            errors: $results['errors'],
        );
    }

    private function processSingleInvoice(Invoice $invoice): ProcessResult
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                $this->logger->debug('Processing invoice', [
                    'invoice_id' => $invoice->getId(),
                    'attempt' => $attempts + 1,
                ]);

                $paymentResult = $this->paymentGateway->charge($invoice);

                if ($paymentResult->isSuccessful()) {
                    $invoice->markAsPaid($paymentResult->getTransactionId());
                    $this->invoiceRepository->save($invoice);

                    $this->notificationService->sendPaymentConfirmation($invoice);

                    return ProcessResult::success();
                }

                throw new \RuntimeException('Payment declined: ' . $paymentResult->getDeclineReason());

            } catch (\Throwable $e) {
                $attempts++;

                $this->logger->warning('Invoice processing attempt failed', [
                    'invoice_id' => $invoice->getId(),
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts >= $maxAttempts) {
                    $invoice->markAsFailed($e->getMessage());
                    $this->invoiceRepository->save($invoice);

                    $this->notificationService->sendPaymentFailedNotice($invoice, $e->getMessage());

                    return ProcessResult::failure($e->getMessage());
                }

                usleep(self::RETRY_DELAY_MS * 1000);
            }
        }

        return ProcessResult::failure('Max attempts exceeded');
    }
}
