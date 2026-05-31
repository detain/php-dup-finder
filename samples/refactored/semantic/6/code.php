<?php

declare(strict_types=1);

namespace Acme\Shared\Policy;

use Acme\Shared\Model\Invoice;
use DateInterval;
use DateTimeImmutable;

final class InvoiceOverduePolicy
{
    public function __construct(
        private int $graceDays = 7,
        private ?DateTimeImmutable $clock = null,
    ) {
    }

    public function isOverdue(Invoice $invoice): bool
    {
        $now = $this->clock ?? new DateTimeImmutable();
        $cutoff = $invoice->dueDate()->add(new DateInterval('P' . $this->graceDays . 'D'));

        if ($now <= $cutoff) {
            return false;
        }

        return $invoice->outstandingBalance() > 0;
    }
}

final class DunningJob
{
    public function __construct(
        private InvoiceOverduePolicy $policy,
        private InvoiceRepositoryInterface $invoices,
    ) {}

    public function run(): int
    {
        $count = 0;
        foreach ($this->invoices->openInvoices() as $invoice) {
            if ($this->policy->isOverdue($invoice)) {
                $count++;
            }
        }
        return $count;
    }
}
