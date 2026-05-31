<?php
declare(strict_types=1);

namespace Acme\Notifications\Password;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mailer\MailerInterface;
use Psr\Log\LoggerInterface;

final class PasswordResetNotifier
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $log,
    ) {}

    public function notify(string $userEmail, string $resetToken): void
    {
        $resetUrl = 'https://acme.io/reset?token=' . urlencode($resetToken);

        $email = (new Email())
            ->from('Acme Support <no-reply@acme.io>')
            ->replyTo('support@acme.io')
            ->to($userEmail)
            ->subject('Reset your Acme password')
            ->html(sprintf('<p>Click <a href="%s">here</a> to reset.</p>', $resetUrl));

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

        $this->log->info('password.reset.send', ['to' => $userEmail]);
        $this->mailer->send($signed);
    }
}
