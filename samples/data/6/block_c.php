<?php
declare(strict_types=1);

namespace App\Notifications\Handlers;

use App\Events\InvoicePaid;
use App\Mail\MailerInterface;
use App\Database\Connection;
use App\Pdf\InvoicePdfBuilder;

final class InvoicePaidHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private Connection $db,
        private InvoicePdfBuilder $pdfBuilder,
    ) {
    }

    public function handle(InvoicePaid $event): void
    {
        $invoice = $this->db->fetchOne(
            'SELECT id, customer_id, total_cents, paid_at, invoice_number FROM invoices WHERE id = ?',
            [$event->invoiceId]
        );

        if ($invoice === null) {
            throw new \RuntimeException('Invoice not found: ' . $event->invoiceId);
        }

        $customer = $this->db->fetchOne(
            'SELECT email, name, company FROM customers WHERE id = ?',
            [(int)$invoice['customer_id']]
        );

        if ($customer === null || empty($customer['email'])) {
            return;
        }

        $pdfBytes = $this->pdfBuilder->renderReceipt((int)$invoice['id']);
        $amount = number_format(((int)$invoice['total_cents']) / 100.0, 2, '.', ',');

        $body = sprintf(
            "Hi %s,\n\nThanks for your payment of \$%s for invoice %s.\nA receipt is attached.\n\nRegards,\nBilling team",
            $customer['name'],
            $amount,
            $invoice['invoice_number']
        );

        $this->mailer->send([
            'from'      => 'noreply@example.com',
            'reply_to'  => 'billing@example.com',
            'to'        => $customer['email'],
            'subject'   => 'Payment received for ' . $invoice['invoice_number'],
            'text_body' => $body,
            'attachments' => [
                [
                    'filename' => 'receipt-' . $invoice['invoice_number'] . '.pdf',
                    'mime'     => 'application/pdf',
                    'content'  => $pdfBytes,
                ],
            ],
        ]);
    }
}
