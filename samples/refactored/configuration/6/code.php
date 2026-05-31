<?php
declare(strict_types=1);

namespace Acme\Notifications;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Crypto\DkimSigner;

final class MailIdentity
{
    public const FROM          = 'Acme Support <no-reply@acme.io>';
    public const REPLY_TO      = 'support@acme.io';
    public const DKIM_KEY_PATH = 'file:///etc/acme/dkim/acme.io.private';
    public const DKIM_DOMAIN   = 'acme.io';
    public const DKIM_SELECTOR = 'mail2024';

    public static function brand(Email $email): Email
    {
        return $email->from(self::FROM)->replyTo(self::REPLY_TO);
    }

    public static function sign(Email $email): Email
    {
        $dkim = new DkimSigner(self::DKIM_KEY_PATH, self::DKIM_DOMAIN, self::DKIM_SELECTOR);

        return $dkim->sign($email, [
            'algorithm'    => 'rsa-sha256',
            'headers'      => ['From', 'To', 'Subject', 'Date'],
            'header_canon' => 'relaxed',
            'body_canon'   => 'relaxed',
        ]);
    }
}

// Usage:
// $email = MailIdentity::brand((new Email())->to($to)->subject($subj)->html($html));
// $this->mailer->send(MailIdentity::sign($email));
