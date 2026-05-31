<?php
declare(strict_types=1);

namespace Stripe\Billing\Service;

use Stripe\Billing\Repository\InvoiceRepository;
use Stripe\Billing\Repository\PaymentMethodRepository;
use Stripe\Billing\Repository\CustomerRepository;
use Stripe\Billing\Entity\Invoice;
use Stripe\Billing\Entity\InvoiceLineItem;
use Stripe\Billing\Entity\PaymentAttempt;
use Stripe\Billing\Exception\InvoiceException;
use Stripe\Billing\Service\TaxCalculator;
use Stripe\Billing\Service\NotificationService;
use Psr\Log\LoggerInterface;

final class InvoiceLifecycleService
{
    private InvoiceRepository $invoiceRepo;
    private PaymentMethodRepository $paymentMethodRepo;
    private CustomerRepository $customerRepo;
    private TaxCalculator $taxCalculator;
    private NotificationService $notificationService;
    private LoggerInterface $logger;

    public function __construct(
        InvoiceRepository $invoiceRepo,
        PaymentMethodRepository $paymentMethodRepo,
        CustomerRepository $customerRepo,
        TaxCalculator $taxCalculator,
        NotificationService $notificationService,
        LoggerInterface $logger
    ) {
        $this->invoiceRepo = $invoiceRepo;
        $this->paymentMethodRepo = $paymentMethodRepo;
        $this->customerRepo = $customerRepo;
        $this->taxCalculator = $taxCalculator;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
    }

    public function createInvoice(string $customerId, array $lineItems): InvoiceCreationResult
    {
        $this->logger->info('Creating invoice', [
            'customer_id' => $customerId,
            'line_items_count' => count($lineItems)
        ]);

        $customer = $this->customerRepo->findById($customerId);
        if ($customer === null) {
            throw new InvoiceException("Customer not found: {$customerId}");
        }

        $defaultPaymentMethod = $this->paymentMethodRepo->findDefaultForCustomer($customerId);
        if ($defaultPaymentMethod === null) {
            throw new InvoiceException("No default payment method for customer: {$customerId}");
        }

        $invoice = Invoice::create([
            'customer_id' => $customerId,
            'status' => 'draft',
            'currency' => $customer->getCurrency(),
            'billing_reason' => $lineItems[0]['reason'] ?? 'subscription',
            'due_date' => (new \DateTimeImmutable())->modify('+30 days'),
            'created_at' => new \DateTimeImmutable()
        ]);

        $savedInvoice = $this->invoiceRepo->save($invoice);
        $this->logger->debug('Invoice record created', ['invoice_id' => $savedInvoice->getId()]);

        $subtotal = 0;
        foreach ($lineItems as $item) {
            $lineItem = InvoiceLineItem::create([
                'invoice_id' => $savedInvoice->getId(),
                'description' => $item['description'],
                'amount' => $item['amount'],
                'currency' => $customer->getCurrency(),
                'quantity' => $item['quantity'] ?? 1,
                'period_start' => $item['period_start'] ?? null,
                'period_end' => $item['period_end'] ?? null,
                'created_at' => new \DateTimeImmutable()
            ]);

            $this->invoiceRepo->saveLineItem($lineItem);
            $subtotal += $item['amount'] * ($item['quantity'] ?? 1);
        }

        $taxAmount = $this->taxCalculator->calculateTax($customerId, $subtotal, $customer->getCurrency());
        $this->invoiceRepo->applyTax($savedInvoice->getId(), $taxAmount);

        $total = $subtotal + $taxAmount;
        $this->invoiceRepo->updateAmounts($savedInvoice->getId(), $subtotal, $taxAmount, $total);

        $this->logger->info('Invoice created successfully', [
            'invoice_id' => $savedInvoice->getId(),
            'subtotal' => $subtotal,
            'tax' => $taxAmount,
            'total' => $total
        ]);

        return new InvoiceCreationResult([
            'success' => true,
            'invoice_id' => $savedInvoice->getId(),
            'subtotal' => $subtotal,
            'tax' => $taxAmount,
            'total' => $total,
            'currency' => $customer->getCurrency()
        ]);
    }

    public function finalizeInvoice(string $invoiceId): FinalizeResult
    {
        $invoice = $this->invoiceRepo->findById($invoiceId);
        if ($invoice === null) {
            throw new InvoiceException("Invoice not found: {$invoiceId}");
        }

        if ($invoice->getStatus() !== 'draft') {
            throw new InvoiceException("Invoice cannot be finalized in status: {$invoice->getStatus()}");
        }

        $this->invoiceRepo->updateStatus($invoiceId, 'finalized');
        $this->invoiceRepo->setNumber($invoiceId, $this->generateInvoiceNumber($invoice));

        $this->logger->info('Invoice finalized', [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoice->getNumber()
        ]);

        return new FinalizeResult([
            'success' => true,
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoice->getNumber(),
            'finalized_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }

    public function sendInvoice(string $invoiceId): SendResult
    {
        $invoice = $this->invoiceRepo->findById($invoiceId);
        if ($invoice === null) {
            throw new InvoiceException("Invoice not found: {$invoiceId}");
        }

        if ($invoice->getStatus() !== 'finalized') {
            throw new InvoiceException("Invoice must be finalized before sending, current status: {$invoice->getStatus()}");
        }

        $this->invoiceRepo->updateStatus($invoiceId, 'sent');

        try {
            $this->notificationService->sendInvoiceNotification(
                $invoice->getCustomerId(),
                $invoiceId
            );

            $this->logger->info('Invoice sent', [
                'invoice_id' => $invoiceId,
                'customer_id' => $invoice->getCustomerId()
            ]);

            return new SendResult([
                'success' => true,
                'invoice_id' => $invoiceId,
                'sent_at' => (new \DateTimeImmutable())->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->invoiceRepo->updateStatus($invoiceId, 'send_failed');
            $this->logger->error('Invoice send failed', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function generateInvoiceNumber(Invoice $invoice): string
    {
        $prefix = date('Y');
        $sequence = $this->invoiceRepo->getNextSequenceForYear($prefix);
        return "INV-{$prefix}-" . str_pad((string)$sequence, 6, '0', STR_PAD_LEFT);
    }
}
