<?php
declare(strict_types=1);

namespace Acme\Notifications\Welcome;

final class WelcomeMailer
{
    public function __construct(private \Acme\Mail\Transport $transport) {}

    public function send(string $to, string $firstName): void
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

        $body = "Hi {$firstName},\n\nWelcome to Acme! Your account is ready.\n\n" . $signature;

        $this->transport->send(
            to: $to,
            subject: 'Welcome to Acme',
            body: $body,
        );
    }

    public function sendVerification(string $to, string $firstName, string $code): void
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

        $body = "Hi {$firstName},\n\nYour code is {$code}. It expires in 15 minutes.\n\n" . $signature;
        $this->transport->send(to: $to, subject: 'Verify your email', body: $body);
    }
}
