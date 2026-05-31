<?php
declare(strict_types=1);

namespace Acme\Notifications\Billing;

final class InvoiceMailer
{
    public function __construct(private \Acme\Mail\Transport $transport) {}

    public function sendInvoice(string $to, string $customer, string $invoiceNo, string $amount): void
    {
        $signature = <<<SIG
        --
        Acme Industries, Inc.
        1200 Market Street, Suite 400
        Philadelphia, PA 19107
        Support: +1 (215) 555-0188
        Hours: Mon-Fri 9am-6pm ET

        You are receiving this email because you signed up at acme.example.com.
        To unsubscribe click: https://acme.example.com/unsub?token={TOKEN}

        Acme Industries is a registered trademark. All rights reserved.
        This email and any attachments are confidential.
        SIG;

        $body = "Hello {$customer},\n\nInvoice {$invoiceNo} for {$amount} is attached.\n\n" . $signature;

        $this->transport->send(
            to: $to,
            subject: "Invoice {$invoiceNo}",
            body: $body,
        );
    }

    public function sendReceipt(string $to, string $customer, string $amount): void
    {
        $signature = <<<SIG
        --
        Acme Industries, Inc.
        1200 Market Street, Suite 400
        Philadelphia, PA 19107
        Support: +1 (215) 555-0188
        Hours: Mon-Fri 9am-6pm ET

        You are receiving this email because you signed up at acme.example.com.
        To unsubscribe click: https://acme.example.com/unsub?token={TOKEN}

        Acme Industries is a registered trademark. All rights reserved.
        This email and any attachments are confidential.
        SIG;

        $body = "Thanks {$customer}, we received your payment of {$amount}.\n\n" . $signature;
        $this->transport->send(to: $to, subject: 'Payment received', body: $body);
    }
}
