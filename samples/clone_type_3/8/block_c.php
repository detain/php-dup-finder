<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Event\InvoiceCreatedEvent;
use App\Event\InvoiceSentEvent;
use App\Event\InvoicePaidEvent;
use App\Event\InvoiceOverdueEvent;
use App\Service\NotificationService;
use App\Service\AccountingService;
use App\Service\AnalyticsService;
use Psr\Log\LoggerInterface;

final class InvoiceEventSubscriber
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly AccountingService $accountingService,
        private readonly AnalyticsService $analyticsService,
        private readonly LoggerInterface $logger,
    ) {}

    public function onInvoiceCreated(InvoiceCreatedEvent $event): void
    {
        $invoice = $event->getInvoice();

        $this->accountingService->createInvoiceEntry($invoice);

        $this->analyticsService->trackEvent('invoice_created', [
            'invoice_id' => $invoice->getId(),
            'client_id' => $invoice->getClientId(),
            'amount' => $invoice->getTotal(),
        ]);

        $this->logger->info('Invoice created event processed', [
            'invoice_id' => $invoice->getId(),
        ]);
    }

    public function onInvoiceSent(InvoiceSentEvent $event): void
    {
        $invoice = $event->getInvoice();
        $sendMethod = $event->getSendMethod();

        $this->notificationService->sendInvoiceNotification($invoice, $sendMethod);

        $this->analyticsService->trackEvent('invoice_sent', [
            'invoice_id' => $invoice->getId(),
            'send_method' => $sendMethod,
        ]);

        $this->logger->info('Invoice sent event processed', [
            'invoice_id' => $invoice->getId(),
            'send_method' => $sendMethod,
        ]);
    }

    public function onInvoicePaid(InvoicePaidEvent $event): void
    {
        $invoice = $event->getInvoice();
        $paymentMethod = $event->getPaymentMethod();

        $this->accountingService->recordPayment($invoice, $paymentMethod);
        $this->notificationService->sendPaymentReceipt($invoice);

        $this->analyticsService->trackEvent('invoice_paid', [
            'invoice_id' => $invoice->getId(),
            'payment_method' => $paymentMethod,
            'amount' => $event->getAmountPaid(),
        ]);

        $this->logger->info('Invoice paid event processed', [
            'invoice_id' => $invoice->getId(),
            'payment_method' => $paymentMethod,
        ]);
    }

    public function onInvoiceOverdue(InvoiceOverdueEvent $event): void
    {
        $invoice = $event->getInvoice();
        $daysOverdue = $event->getDaysOverdue();

        $this->notificationService->sendOverdueReminder($invoice, $daysOverdue);

        $this->analyticsService->trackEvent('invoice_overdue', [
            'invoice_id' => $invoice->getId(),
            'days_overdue' => $daysOverdue,
        ]);

        $this->logger->info('Invoice overdue event processed', [
            'invoice_id' => $invoice->getId(),
            'days_overdue' => $daysOverdue,
        ]);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InvoiceCreatedEvent::class => 'onInvoiceCreated',
            InvoiceSentEvent::class => 'onInvoiceSent',
            InvoicePaidEvent::class => 'onInvoicePaid',
            InvoiceOverdueEvent::class => 'onInvoiceOverdue',
        ];
    }
}
