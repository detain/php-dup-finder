<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\InvoiceRepository;
use App\Repository\CustomerRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class InvoiceCacheHandler
{
    private const CACHE_PREFIX = 'invoice';
    private const DEFAULT_TTL = 86400;
    private const STALE_TTL = 3600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getInvoice(int $invoiceId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildInvoiceCacheKey($invoiceId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'invoice']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'invoice']);

        $invoice = $this->invoiceRepository->find($invoiceId);

        if ($invoice === null) {
            return null;
        }

        $data = $this->serializeInvoice($invoice);
        $this->setInvoice($invoiceId, $data);

        return $data;
    }

    public function setInvoice(int $invoiceId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildInvoiceCacheKey($invoiceId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached invoice', [
            'invoice_id' => $invoiceId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateInvoice(int $invoiceId): void
    {
        $cacheKey = $this->buildInvoiceCacheKey($invoiceId);

        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated invoice cache', [
            'invoice_id' => $invoiceId,
        ]);
    }

    public function invalidateCustomerInvoices(int $customerId): void
    {
        $invoices = $this->invoiceRepository->findByCustomerId($customerId);

        $cacheKeys = array_map(
            fn($invoice) => $this->buildInvoiceCacheKey($invoice->getId()),
            $invoices
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateCustomerInvoiceSummary($customerId);

        $this->logger->info('Invalidated invoices for customer', [
            'customer_id' => $customerId,
            'invoice_count' => count($invoices),
        ]);
    }

    public function refreshInvoice(int $invoiceId): void
    {
        $cacheKey = $this->buildInvoiceCacheKey($invoiceId);

        $invoice = $this->invoiceRepository->find($invoiceId);

        if ($invoice === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeInvoice($invoice);
        $this->setInvoice($invoiceId, $data);

        $this->logger->debug('Refreshed invoice cache', [
            'invoice_id' => $invoiceId,
        ]);
    }

    public function warmCustomer(int $customerId): void
    {
        $invoices = $this->invoiceRepository->findRecentByCustomerId($customerId, 100);

        foreach ($invoices as $invoice) {
            $data = $this->serializeInvoice($invoice);
            $this->setInvoice($invoice->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed invoice cache for customer', [
            'customer_id' => $customerId,
            'invoices_warmed' => count($invoices),
        ]);
    }

    public function handlePaymentReceived(int $invoiceId): void
    {
        $this->invalidateInvoice($invoiceId);

        $paymentKeys = [
            $this->keyBuilder->build('invoice', $invoiceId, 'payment_info'),
            $this->keyBuilder->build('invoice', $invoiceId, 'balance'),
            $this->keyBuilder->build('invoice', $invoiceId, 'receipt'),
        ];

        foreach ($paymentKeys as $key) {
            $this->cache->delete($key);
        }

        $invoice = $this->invoiceRepository->find($invoiceId);
        if ($invoice !== null) {
            $this->invalidateCustomerInvoices($invoice->getCustomerId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'invoice_payment_received',
            'invoice_id' => (string) $invoiceId,
        ]);

        $this->logger->info('Handled payment received cache invalidation', [
            'invoice_id' => $invoiceId,
        ]);
    }

    public function handleInvoiceAdjustment(int $invoiceId): void
    {
        $this->invalidateInvoice($invoiceId);

        $adjustmentKeys = [
            $this->keyBuilder->build('invoice', $invoiceId, 'line_items'),
            $this->keyBuilder->build('invoice', $invoiceId, 'tax_info'),
            $this->keyBuilder->build('invoice', $invoiceId, 'total'),
        ];

        foreach ($adjustmentKeys as $key) {
            $this->cache->delete($key);
        }

        $invoice = $this->invoiceRepository->find($invoiceId);
        if ($invoice !== null) {
            $this->invalidateCustomerInvoices($invoice->getCustomerId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'invoice_adjustment',
            'invoice_id' => (string) $invoiceId,
        ]);

        $this->logger->info('Handled invoice adjustment cache invalidation', [
            'invoice_id' => $invoiceId,
        ]);
    }

    public function handleVoidInvoice(int $invoiceId): void
    {
        $this->invalidateInvoice($invoiceId);

        $voidKeys = [
            $this->keyBuilder->build('invoice', $invoiceId, 'void_reason'),
            $this->keyBuilder->build('invoice', $invoiceId, 'credit_note'),
        ];

        foreach ($voidKeys as $key) {
            $this->cache->delete($key);
        }

        $invoice = $this->invoiceRepository->find($invoiceId);
        if ($invoice !== null) {
            $this->invalidateCustomerInvoices($invoice->getCustomerId());
        }

        $this->logger->info('Handled void invoice cache invalidation', [
            'invoice_id' => $invoiceId,
        ]);
    }

    public function handleSentInvoice(int $invoiceId): void
    {
        $this->invalidateInvoice($invoiceId);

        $sentKeys = [
            $this->keyBuilder->build('invoice', $invoiceId, 'delivery_status'),
            $this->keyBuilder->build('invoice', $invoiceId, 'read_receipt'),
        ];

        foreach ($sentKeys as $key) {
            $this->cache->delete($key);
        }

        $this->logger->info('Handled sent invoice cache invalidation', [
            'invoice_id' => $invoiceId,
        ]);
    }

    public function setWithStale(int $invoiceId, array $data): void
    {
        $cacheKey = $this->buildInvoiceCacheKey($invoiceId);
        $staleKey = $cacheKey . ':stale';

        $this->cache->set($staleKey, $data, self::DEFAULT_TTL + self::STALE_TTL);
        $this->cache->set($cacheKey, $data, self::DEFAULT_TTL);

        $this->logger->debug('Set invoice with stale backup', [
            'invoice_id' => $invoiceId,
        ]);
    }

    public function getOrSet(int $invoiceId, callable $fetcher, ?int $ttl = null): array
    {
        $cached = $this->getInvoice($invoiceId);

        if ($cached !== null) {
            return $cached;
        }

        $data = $fetcher($invoiceId);

        if ($data !== null) {
            $this->setInvoice($invoiceId, $data, $ttl);
        }

        return $data;
    }

    private function buildInvoiceCacheKey(int $invoiceId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'invoice', $invoiceId);
    }

    private function buildCustomerInvoiceSummaryCacheKey(int $customerId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'customer', $customerId, 'invoice_summary');
    }

    private function invalidateCustomerInvoiceSummary(int $customerId): void
    {
        $summaryKey = $this->buildCustomerInvoiceSummaryCacheKey($customerId);
        $this->cache->delete($summaryKey);
    }

    private function serializeInvoice(object $invoice): array
    {
        return [
            'id' => $invoice->getId(),
            'customer_id' => $invoice->getCustomerId(),
            'invoice_number' => $invoice->getInvoiceNumber(),
            'total' => $invoice->getTotal(),
            'balance_due' => $invoice->getBalanceDue(),
            'status' => $invoice->getStatus(),
            'due_date' => $invoice->getDueDate()?->format(\DATE_ATOM),
            'issued_at' => $invoice->getIssuedAt()?->format(\DATE_ATOM),
        ];
    }
}
