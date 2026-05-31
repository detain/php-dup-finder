<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Invoice;
use App\Repository\InvoiceRepository;
use App\Service\LedgerService;
use App\Service\PaymentProcessor;
use App\Event\InvoicePaidEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class InvoiceProcessingService
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly LedgerService $ledgerService,
        private readonly PaymentProcessor $paymentProcessor,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function settleInvoice(int $invoiceId): Invoice
    {
        $invoice = $this->invoiceRepository->findById($invoiceId);

        if ($invoice === null) {
            throw new \RuntimeException("Invoice {$invoiceId} not found");
        }

        if ($invoice->getStatus() !== 'issued') {
            throw new \RuntimeException("Invoice {$invoiceId} cannot be settled - invalid status");
        }

        $lineItems = $invoice->getLineItems();
        foreach ($lineItems as $item) {
            $hasFunds = $this->ledgerService->verifyFunds(
                $item->getAccountId(),
                $item->getAmount()
            );

            if (!$hasFunds) {
                $this->logger->warning('Insufficient funds for invoice line item', [
                    'invoice_id' => $invoiceId,
                    'account_id' => $item->getAccountId(),
                    'amount' => $item->getAmount(),
                ]);
                throw new \RuntimeException("Insufficient funds in account {$item->getAccountId()}");
            }
        }

        foreach ($lineItems as $item) {
            $this->ledgerService->holdFunds(
                $item->getAccountId(),
                $item->getAmount()
            );
        }

        $paymentReference = $this->paymentProcessor->processPayment(
            $invoice->getClientId(),
            $invoice->getTotalAmount(),
            $invoice->getPaymentMethod()
        );

        if ($paymentReference === null) {
            foreach ($lineItems as $item) {
                $this->ledgerService->releaseFunds(
                    $item->getAccountId(),
                    $item->getAmount()
                );
            }
            throw new \RuntimeException("Payment processing failed for invoice {$invoiceId}");
        }

        $invoice->setStatus('paid');
        $invoice->setPaymentReference($paymentReference);
        $invoice->setPaidAt(new \DateTimeImmutable());
        $this->invoiceRepository->save($invoice);

        $this->eventDispatcher->dispatch(
            new InvoicePaidEvent($invoice),
            InvoicePaidEvent::NAME
        );

        $this->logger->info('Invoice settled successfully', [
            'invoice_id' => $invoiceId,
            'payment_reference' => $paymentReference,
        ]);

        return $invoice;
    }
}
