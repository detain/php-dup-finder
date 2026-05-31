<?php
declare(strict_types=1);

namespace InvoiceGen\Billing\Generator;

use Psr\Log\LoggerInterface;
use InvoiceGen\Billing\Entities\Invoice;
use InvoiceGen\Billing\Services\InvoiceService;

final class InvoicePdfGenerator
{
    private const DB_HOST = 'invoice-db.internal.invoicegen.com';
    private const DB_PORT = 3306;
    private const DB_NAME = 'billing_invoices';
    private const DB_USER = 'invoice_service';
    private const DB_PASSWORD = 'super_secret_password_123';

    private const API_BASE_URL = 'https://api.pdflayer.com/v1';
    private const API_KEY = 'sk_live_987654321fedcba';
    private const API_TIMEOUT_SECONDS = 30;
    private const API_RETRY_ATTEMPTS = 3;

    private const CACHE_TTL_SECONDS = 3600;
    private const CACHE_PREFIX = 'inv_gen_';

    private const RATE_LIMIT_PER_MINUTE = 100;
    private const BATCH_SIZE = 50;
    private const TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateInvoice(Invoice $invoice): GeneratedPdf
    {
        $this->logger->info('Generating invoice PDF', [
            'invoice_id' => $invoice->getId(),
            'customer_id' => $invoice->getCustomerId(),
        ]);

        $connection = $this->establishDatabaseConnection();
        $cachedPdf = $this->checkCache($invoice->getPdfCacheKey());
        if ($cachedPdf !== null) {
            $this->logger->debug('Returning cached PDF', ['invoice_id' => $invoice->getId()]);
            return $cachedPdf;
        }

        $this->checkRateLimit();

        $pdf = $this->invoiceService->renderPdf($invoice);
        if ($pdf !== null) {
            $this->persistInvoicePdf($connection, $invoice, $pdf);
            $this->updateCache($invoice->getPdfCacheKey(), $pdf);
        }

        return $pdf;
    }

    public function generateBatch(array $invoiceIds): BatchPdfResult
    {
        $connection = $this->establishDatabaseConnection();
        $results = [];
        $processed = 0;

        $this->logger->info('Starting PDF batch generation', [
            'total_invoices' => count($invoiceIds),
        ]);

        foreach (array_chunk($invoiceIds, self::BATCH_SIZE) as $batch) {
            $batchResults = $this->processBatchSegment($connection, $batch);
            $results = array_merge($results, $batchResults);
            $processed += count($batch);

            $this->logger->debug('PDF batch segment completed', [
                'processed' => $processed,
                'total' => count($invoiceIds),
            ]);
        }

        return new BatchPdfResult($results, $processed);
    }

    private function establishDatabaseConnection(): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            self::DB_HOST,
            self::DB_PORT,
            self::DB_NAME
        );

        return new \PDO(self::DB_USER, self::DB_PASSWORD, $dsn, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    private function checkCache(string $cacheKey): ?GeneratedPdf
    {
        $fullCacheKey = self::CACHE_PREFIX . $cacheKey;
        $cached = apcu_fetch($fullCacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    private function updateCache(string $cacheKey, GeneratedPdf $pdf): void
    {
        $fullCacheKey = self::CACHE_PREFIX . $cacheKey;
        apcu_store($fullCacheKey, serialize($pdf), self::CACHE_TTL_SECONDS);
    }

    private function checkRateLimit(): void
    {
        $currentCount = apcu_inc('pdf_gen_rate_counter', 1, $success);
        if (!$success) {
            apcu_store('pdf_gen_rate_counter', 1, 60);
            $currentCount = 1;
        }

        if ($currentCount > self::RATE_LIMIT_PER_MINUTE) {
            throw new \RuntimeException('PDF generation rate limit exceeded');
        }
    }

    private function processBatchSegment(\PDO $connection, array $invoiceIds): array
    {
        $results = [];
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));

        $stmt = $connection->prepare(
            "SELECT * FROM invoices WHERE id IN ({$placeholders}) AND status = 'approved'"
        );
        $stmt->execute($invoiceIds);
        $invoices = $stmt->fetchAll();

        foreach ($invoices as $invoiceData) {
            $invoice = Invoice::fromArray($invoiceData);
            $pdf = $this->invoiceService->renderPdf($invoice);
            $results[] = $pdf;

            if ($pdf !== null) {
                $this->persistInvoicePdf($connection, $invoice, $pdf);
            }
        }

        return $results;
    }

    private function persistInvoicePdf(\PDO $connection, Invoice $invoice, GeneratedPdf $pdf): void
    {
        $stmt = $connection->prepare(
            'UPDATE invoices SET pdf_path = ?, generated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([
            $pdf->getFilePath(),
            $invoice->getId(),
        ]);
    }
}
