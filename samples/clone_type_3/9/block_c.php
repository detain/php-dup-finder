<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Service\MailService;
use App\Repository\InvoiceRepository;
use Psr\Log\LoggerInterface;

final class SendInvoiceReminderJob
{
    public function __construct(
        private readonly MailService $mailService,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(int $invoiceId): bool
    {
        $invoice = $this->invoiceRepository->find($invoiceId);

        if ($invoice === null) {
            $this->logger->error('Invoice not found for reminder email', [
                'invoice_id' => $invoiceId,
            ]);
            return false;
        }

        if ($invoice->getStatus() !== 'sent') {
            $this->logger->info('Invoice not in sent status, skipping reminder', [
                'invoice_id' => $invoiceId,
                'status' => $invoice->getStatus(),
            ]);
            return true;
        }

        try {
            $daysUntilDue = $invoice->getDaysUntilDue();

            $result = $this->mailService->send(
                $invoice->getClient()->getEmail(),
                $daysUntilDue <= 3 ? 'invoice_reminder_urgent' : 'invoice_reminder',
                [
                    'invoice_number' => $invoice->getNumber(),
                    'client_name' => $invoice->getClient()->getName(),
                    'amount_due' => $invoice->getTotal(),
                    'due_date' => $invoice->getDueDate()->format('Y-m-d'),
                    'days_remaining' => max(0, $daysUntilDue),
                ]
            );

            if ($result) {
                $this->logger->info('Invoice reminder email sent', [
                    'invoice_id' => $invoiceId,
                    'client_email' => $invoice->getClient()->getEmail(),
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send invoice reminder email', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
