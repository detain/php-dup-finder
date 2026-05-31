<?php

declare(strict_types=1);

namespace Acme\Dunning\Jobs;

use Acme\Dunning\Repository\InvoiceRepository;
use Acme\Dunning\Mailer\DunningMailer;
use Psr\Log\LoggerInterface;

final class DunningJob
{
    private const GRACE_DAYS = 7;

    public function __construct(
        private InvoiceRepository $invoices,
        private DunningMailer $mailer,
        private LoggerInterface $log,
    ) {
    }

    public function run(): int
    {
        $count = 0;

        foreach ($this->invoices->openInvoices() as $invoice) {
            $dueTs = strtotime($invoice->dueDate());
            $cutoffTs = $dueTs + (self::GRACE_DAYS * 86400);
            $nowTs = time();

            $outstanding = $invoice->amountDue() - $invoice->amountPaid();

            $isOverdue = $nowTs > $cutoffTs && $outstanding > 0;

            if ($isOverdue) {
                $this->mailer->sendDunningNotice($invoice);
                $this->log->info('Dunning sent for invoice ' . $invoice->id());
                $count++;
            }
        }

        return $count;
    }
}
