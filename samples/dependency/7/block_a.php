<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Infrastructure\EventDispatcher\EventDispatcherInterface;

/**
 * Billing and invoice management service.
 * The EventDispatcherInterface is manually injected here, duplicated across
 * all services that dispatch domain events.
 */
class BillingService
{
    private EventDispatcherInterface $eventDispatcher;
    private InvoiceRepositoryInterface $invoiceRepository;
    private PaymentGatewayInterface $paymentGateway;

    public function __construct(
        InvoiceRepositoryInterface $invoiceRepository,
        PaymentGatewayInterface $paymentGateway,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->invoiceRepository = $invoiceRepository;
        $this->paymentGateway = $paymentGateway;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function createInvoice(string $customerId, array $lineItems): Invoice
    {
        $invoice = Invoice::create(
            customerId: $customerId,
            lineItems: $lineItems,
            dueDate: new \DateTimeImmutable('+30 days'),
        );

        $savedInvoice = $this->invoiceRepository->save($invoice);

        $this->eventDispatcher->dispatch(new InvoiceCreatedEvent($savedInvoice));

        return $savedInvoice;
    }

    public function sendInvoice(string $invoiceId): void
    {
        $invoice = $this->invoiceRepository->findById($invoiceId);

        if ($invoice === null) {
            throw new InvoiceNotFoundException("Invoice not found: {$invoiceId}");
        }

        $invoice->markAsSent();
        $this->invoiceRepository->save($invoice);

        $this->eventDispatcher->dispatch(new InvoiceSentEvent($invoice));
    }

    public function processPayment(string $invoiceId, string $paymentMethodId): Payment
    {
        $invoice = $this->invoiceRepository->findById($invoiceId);

        if ($invoice === null) {
            throw new InvoiceNotFoundException("Invoice not found: {$invoiceId}");
        }

        if ($invoice->isPaid()) {
            throw new InvoiceAlreadyPaidException("Invoice is already paid");
        }

        $payment = $this->paymentGateway->charge(
            amount: $invoice->getTotalAmount(),
            currency: $invoice->getCurrency(),
            paymentMethodId: $paymentMethodId,
        );

        if (!$payment->isSuccessful()) {
            $this->eventDispatcher->dispatch(new PaymentFailedEvent($invoice, $payment));
            throw new PaymentFailedException($payment->getErrorMessage());
        }

        $invoice->markAsPaid($payment);
        $this->invoiceRepository->save($invoice);

        $this->eventDispatcher->dispatch(new PaymentReceivedEvent($invoice, $payment));

        return $payment;
    }

    public function issueRefund(string $invoiceId, float $amount, string $reason): void
    {
        $invoice = $this->invoiceRepository->findById($invoiceId);

        if ($invoice === null) {
            throw new InvoiceNotFoundException("Invoice not found: {$invoiceId}");
        }

        if (!$invoice->isPaid()) {
            throw new InvoiceNotPaidException("Cannot refund an unpaid invoice");
        }

        $refund = $this->paymentGateway->refund(
            transactionId: $invoice->getPayment()->getTransactionId(),
            amount: $amount,
            reason: $reason,
        );

        if (!$refund->isSuccessful()) {
            throw new RefundFailedException($refund->getErrorMessage());
        }

        $invoice->addRefund($amount, $refund);
        $this->invoiceRepository->save($invoice);

        $this->eventDispatcher->dispatch(new RefundIssuedEvent($invoice, $amount, $reason));
    }

    public function sendReminder(string $invoiceId): void
    {
        $invoice = $this->invoiceRepository->findById($invoiceId);

        if ($invoice === null) {
            throw new InvoiceNotFoundException("Invoice not found: {$invoiceId}");
        }

        if ($invoice->isPaid()) {
            return;
        }

        $invoice->incrementReminderCount();
        $this->invoiceRepository->save($invoice);

        $this->eventDispatcher->dispatch(new PaymentReminderSentEvent($invoice));
    }

    public function voidInvoice(string $invoiceId, string $reason): void
    {
        $invoice = $this->invoiceRepository->findById($invoiceId);

        if ($invoice === null) {
            throw new InvoiceNotFoundException("Invoice not found: {$invoiceId}");
        }

        if ($invoice->isPaid()) {
            throw new InvoiceAlreadyPaidException("Cannot void a paid invoice");
        }

        $invoice->void($reason);
        $this->invoiceRepository->save($invoice);

        $this->eventDispatcher->dispatch(new InvoiceVoidedEvent($invoice, $reason));
    }
}
