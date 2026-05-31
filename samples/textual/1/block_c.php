<?php
declare(strict_types=1);

namespace Acme\Notifications\Security;

final class SecurityMailer
{
    public function __construct(private \Acme\Mail\Transport $transport) {}

    public function sendPasswordReset(string $to, string $name, string $link): void
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

        $body = "Hi {$name},\n\nReset your password here: {$link}\n\n" . $signature;
        $this->transport->send(to: $to, subject: 'Password reset', body: $body);
    }

    public function sendLoginAlert(string $to, string $name, string $ip, string $device): void
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

        $body = "Hi {$name},\n\nA new login from {$ip} on {$device} was detected.\n\n" . $signature;
        $this->transport->send(to: $to, subject: 'New login detected', body: $body);
    }
}
