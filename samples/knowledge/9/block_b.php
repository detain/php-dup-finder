<?php

declare(strict_types=1);

namespace App\Billing;

use App\Domain\Invoice;
use App\Domain\CreditMemo;
use App\Exceptions\BillingException;
use App\Repositories\InvoiceRepository;
use App\Repositories\CreditMemoRepository;
use DateTimeImmutable;

final class InvoiceReverser
{
    public function __construct(
        private InvoiceRepository $invoices,
        private CreditMemoRepository $credits,
    ) {}

    public function reverse(int $invoiceId, string $reason, int $issuedByUserId): CreditMemo
    {
        $invoice = $this->invoices->findOrFail($invoiceId);

        if ($invoice->status !== 'paid') {
            throw new BillingException('Only paid invoices can be reversed.');
        }

        $paidAt = $invoice->paidAt;
        $now = new DateTimeImmutable();
        $daysSincePayment = (int) $now->diff($paidAt)->days;

        // Refunds (and the credit memos that back them) are only allowed within 30 days of payment.
        if ($daysSincePayment > 30) {
            throw new BillingException(
                sprintf(
                    'Invoice %d was paid %d days ago; refunds are only allowed within 30 days.',
                    $invoice->id,
                    $daysSincePayment
                )
            );
        }

        $memo = new CreditMemo();
        $memo->invoiceId = $invoice->id;
        $memo->customerId = $invoice->customerId;
        $memo->amountCents = $invoice->totalCents;
        $memo->reason = $reason;
        $memo->issuedAt = $now;
        $memo->issuedByUserId = $issuedByUserId;

        $this->credits->insert($memo);
        $invoice->status = 'refunded';
        $invoice->refundedAt = $now;
        $this->invoices->save($invoice);

        return $memo;
    }
}
