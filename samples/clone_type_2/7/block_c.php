<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Invoice;
use App\Repository\InvoiceRepository;
use App\Service\EmailService;
use App\Service\SmsService;
use Psr\Log\LoggerInterface;

final class InvoiceNotificationService
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly EmailService $emailService,
        private readonly SmsService $smsService,
        private readonly LoggerInterface $logger,
    ) {}

    public function notifyInvoiceCreated(Invoice $invoice): void
    {
        $client = $invoice->getClient();
        $template = 'invoice_created';

        $emailResult = $this->emailService->send(
            $client->getEmail(),
            $this->renderEmailTemplate($template, $invoice),
            'Invoice Generated - #' . $invoice->getNumber()
        );

        if (!$emailResult) {
            $this->logger->error('Failed to send invoice created email', [
                'invoice_id' => $invoice->getId(),
                'client_email' => $client->getEmail(),
            ]);
        }

        if ($client->getPhone()) {
            $smsResult = $this->smsService->send(
                $client->getPhone(),
                $this->renderSmsTemplate($template, $invoice)
            );

            if (!$smsResult) {
                $this->logger->error('Failed to send invoice created SMS', [
                    'invoice_id' => $invoice->getId(),
                    'client_phone' => $client->getPhone(),
                ]);
            }
        }

        $this->logger->info('Invoice created notifications sent', [
            'invoice_id' => $invoice->getId(),
            'client_id' => $client->getId(),
        ]);
    }

    public function notifyInvoiceDueSoon(Invoice $invoice): void
    {
        $client = $invoice->getClient();
        $template = 'invoice_due_soon';

        $emailResult = $this->emailService->send(
            $client->getEmail(),
            $this->renderEmailTemplate($template, $invoice),
            'Payment Due Soon - Invoice #' . $invoice->getNumber()
        );

        if (!$emailResult) {
            $this->logger->error('Failed to send invoice due soon email', [
                'invoice_id' => $invoice->getId(),
                'client_email' => $client->getEmail(),
            ]);
        }

        if ($client->getPhone()) {
            $smsResult = $this->smsService->send(
                $client->getPhone(),
                $this->renderSmsTemplate($template, $invoice)
            );

            if (!$smsResult) {
                $this->logger->error('Failed to send invoice due soon SMS', [
                    'invoice_id' => $invoice->getId(),
                    'client_phone' => $client->getPhone(),
                ]);
            }
        }

        $this->logger->info('Invoice due soon notifications sent', [
            'invoice_id' => $invoice->getId(),
            'client_id' => $client->getId(),
        ]);
    }

    public function notifyInvoiceOverdue(Invoice $invoice): void
    {
        $client = $invoice->getClient();
        $template = 'invoice_overdue';

        $emailResult = $this->emailService->send(
            $client->getEmail(),
            $this->renderEmailTemplate($template, $invoice),
            'Payment Overdue - Invoice #' . $invoice->getNumber()
        );

        if (!$emailResult) {
            $this->logger->error('Failed to send invoice overdue email', [
                'invoice_id' => $invoice->getId(),
                'client_email' => $client->getEmail(),
            ]);
        }

        if ($client->getPhone()) {
            $smsResult = $this->smsService->send(
                $client->getPhone(),
                $this->renderSmsTemplate($template, $invoice)
            );

            if (!$smsResult) {
                $this->logger->error('Failed to send invoice overdue SMS', [
                    'invoice_id' => $invoice->getId(),
                    'client_phone' => $client->getPhone(),
                ]);
            }
        }

        $this->logger->info('Invoice overdue notifications sent', [
            'invoice_id' => $invoice->getId(),
            'client_id' => $client->getId(),
        ]);
    }

    public function notifyInvoicePaid(Invoice $invoice): void
    {
        $client = $invoice->getClient();
        $template = 'invoice_paid';

        $emailResult = $this->emailService->send(
            $client->getEmail(),
            $this->renderEmailTemplate($template, $invoice),
            'Payment Received - Invoice #' . $invoice->getNumber()
        );

        if (!$emailResult) {
            $this->logger->error('Failed to send invoice paid email', [
                'invoice_id' => $invoice->getId(),
                'client_email' => $client->getEmail(),
            ]);
        }

        if ($client->getPhone()) {
            $smsResult = $this->smsService->send(
                $client->getPhone(),
                $this->renderSmsTemplate($template, $invoice)
            );

            if (!$smsResult) {
                $this->logger->error('Failed to send invoice paid SMS', [
                    'invoice_id' => $invoice->getId(),
                    'client_phone' => $client->getPhone(),
                ]);
            }
        }

        $this->logger->info('Invoice paid notifications sent', [
            'invoice_id' => $invoice->getId(),
            'client_id' => $client->getId(),
        ]);
    }

    private function renderEmailTemplate(string $template, Invoice $invoice): string
    {
        return str_replace(
            ['{{invoice_number}}', '{{client_name}}', '{{amount_due}}', '{{due_date}}'],
            [$invoice->getNumber(), $invoice->getClient()->getName(), $invoice->getAmountDue(), $invoice->getDueDate()->format('Y-m-d')],
            file_get_contents(__DIR__ . '/templates/' . $template . '.html')
        );
    }

    private function renderSmsTemplate(string $template, Invoice $invoice): string
    {
        $messageMap = [
            'invoice_created' => 'Invoice %s created. Amount: $%.2f',
            'invoice_due_soon' => 'Invoice %s due on %s. Amount: $%.2f',
            'invoice_overdue' => 'Invoice %s is overdue! Amount: $%.2f',
            'invoice_paid' => 'Invoice %s paid. Thank you!',
        ];

        $format = $messageMap[$template] ?? 'Invoice %s update';

        return sprintf($format, $invoice->getNumber(), $invoice->getAmountDue());
    }
}
