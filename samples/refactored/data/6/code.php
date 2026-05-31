<?php
declare(strict_types=1);

namespace App\Notifications;

final class MailDefaults
{
    public const FROM_ADDRESS = 'noreply@example.com';
    public const SUPPORT_ADDRESS = 'support@example.com';
    public const SECURITY_ADDRESS = 'security@example.com';
    public const BILLING_ADDRESS = 'billing@example.com';
}

namespace App\Notifications\Handlers;

use App\Events\OrderShipped;
use App\Mail\MailerInterface;
use App\Notifications\MailDefaults;

final class OrderShippedHandler
{
    public function __construct(private MailerInterface $mailer) {}

    public function handle(OrderShipped $event): void
    {
        $this->mailer->send([
            'from'     => MailDefaults::FROM_ADDRESS,
            'reply_to' => MailDefaults::SUPPORT_ADDRESS,
            'to'       => $event->recipient,
            'subject'  => sprintf('Your order #%d has shipped', $event->orderId),
            'text_body' => 'Order ' . $event->orderId . ' shipped.',
        ]);
    }
}

namespace App\Notifications\Handlers;

use App\Events\PasswordChanged;
use App\Mail\MailerInterface;
use App\Notifications\MailDefaults;

final class PasswordChangedHandler
{
    public function __construct(private MailerInterface $mailer) {}

    public function handle(PasswordChanged $event): void
    {
        $this->mailer->send([
            'from'     => MailDefaults::FROM_ADDRESS,
            'reply_to' => MailDefaults::SECURITY_ADDRESS,
            'to'       => $event->email,
            'subject'  => 'Security alert: password changed',
        ]);
    }
}

namespace App\Notifications\Handlers;

use App\Events\InvoicePaid;
use App\Mail\MailerInterface;
use App\Notifications\MailDefaults;

final class InvoicePaidHandler
{
    public function __construct(private MailerInterface $mailer) {}

    public function handle(InvoicePaid $event): void
    {
        $this->mailer->send([
            'from'     => MailDefaults::FROM_ADDRESS,
            'reply_to' => MailDefaults::BILLING_ADDRESS,
            'to'       => $event->email,
            'subject'  => 'Payment received',
        ]);
    }
}
