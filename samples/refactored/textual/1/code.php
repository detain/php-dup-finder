<?php
declare(strict_types=1);

namespace Acme\Notifications;

final class EmailSignature
{
    public const TEXT = <<<SIG
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

    public static function appendTo(string $body, string $unsubToken): string
    {
        return $body . "\n" . str_replace('{TOKEN}', $unsubToken, self::TEXT);
    }
}

namespace Acme\Notifications\Welcome;

use Acme\Notifications\EmailSignature;

final class WelcomeMailer
{
    public function __construct(private \Acme\Mail\Transport $transport) {}

    public function send(string $to, string $firstName, string $unsubToken): void
    {
        $body = "Hi {$firstName},\n\nWelcome to Acme! Your account is ready.";
        $this->transport->send(to: $to, subject: 'Welcome to Acme', body: EmailSignature::appendTo($body, $unsubToken));
    }

    public function sendVerification(string $to, string $firstName, string $code, string $unsubToken): void
    {
        $body = "Hi {$firstName},\n\nYour code is {$code}. It expires in 15 minutes.";
        $this->transport->send(to: $to, subject: 'Verify your email', body: EmailSignature::appendTo($body, $unsubToken));
    }
}
