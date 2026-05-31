<?php
declare(strict_types=1);

namespace Acme\Notifications\Receipt;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mailer\MailerInterface;
use Psr\Log\LoggerInterface;

final class ReceiptNotifier
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $log,
    ) {}

    public function notify(string $userEmail, int $orderId, int $cents): void
    {
        $email = (new Email())
            ->from('Acme Support <no-reply@acme.io>')
            ->replyTo('support@acme.io')
            ->to($userEmail)
            ->subject(sprintf('Receipt for order #%d', $orderId))
            ->html(sprintf(
                '<p>Order #%d: $%s</p>',
                $orderId,
                number_format($cents / 100, 2)
            ));

        $dkim = new DkimSigner(
            'file:///etc/acme/dkim/acme.io.private',
            'acme.io',
            'mail2024'
        );
        $signed = $dkim->sign($email, [
            'algorithm'    => 'rsa-sha256',
            'headers'      => ['From', 'To', 'Subject', 'Date'],
            'header_canon' => 'relaxed',
            'body_canon'   => 'relaxed',
        ]);

        $this->log->info('receipt.send', ['to' => $userEmail, 'order' => $orderId]);
        $this->mailer->send($signed);
    }
}
