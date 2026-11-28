<?php
namespace Fixtures\Notify;

class Mailer { public function send(string $role, $user): void {} }

class Notify
{
    public function __construct(private Mailer $mailer) {}

    public function notifyHigh($user, int $score): void
    {
        // E3 6.2 span pad 01 - comments never enter the canonical AST
        // E3 6.2 span pad 02 - comments never enter the canonical AST
        // E3 6.2 span pad 03 - comments never enter the canonical AST
        // E3 6.2 span pad 04 - comments never enter the canonical AST
        // E3 6.2 span pad 05 - comments never enter the canonical AST
        // E3 6.2 span pad 06 - comments never enter the canonical AST
        // E3 6.2 span pad 07 - comments never enter the canonical AST
        // E3 6.2 span pad 08 - comments never enter the canonical AST
        // E3 6.2 span pad 09 - comments never enter the canonical AST
        // E3 6.2 span pad 10 - comments never enter the canonical AST
        // E3 6.2 span pad 11 - comments never enter the canonical AST
        // E3 6.2 span pad 12 - comments never enter the canonical AST
        if ($score > 10) {
            $this->mailer->send('admin', $user);
        }
    }

    public function notifyMid($user, int $score): void
    {
        // E3 6.2 span pad 01 - comments never enter the canonical AST
        // E3 6.2 span pad 02 - comments never enter the canonical AST
        // E3 6.2 span pad 03 - comments never enter the canonical AST
        // E3 6.2 span pad 04 - comments never enter the canonical AST
        // E3 6.2 span pad 05 - comments never enter the canonical AST
        // E3 6.2 span pad 06 - comments never enter the canonical AST
        // E3 6.2 span pad 07 - comments never enter the canonical AST
        // E3 6.2 span pad 08 - comments never enter the canonical AST
        // E3 6.2 span pad 09 - comments never enter the canonical AST
        // E3 6.2 span pad 10 - comments never enter the canonical AST
        // E3 6.2 span pad 11 - comments never enter the canonical AST
        // E3 6.2 span pad 12 - comments never enter the canonical AST
        if ($score > 20) {
            $this->mailer->send('moderator', $user);
        }
    }

    public function notifyLow($user, int $score): void
    {
        // E3 6.2 span pad 01 - comments never enter the canonical AST
        // E3 6.2 span pad 02 - comments never enter the canonical AST
        // E3 6.2 span pad 03 - comments never enter the canonical AST
        // E3 6.2 span pad 04 - comments never enter the canonical AST
        // E3 6.2 span pad 05 - comments never enter the canonical AST
        // E3 6.2 span pad 06 - comments never enter the canonical AST
        // E3 6.2 span pad 07 - comments never enter the canonical AST
        // E3 6.2 span pad 08 - comments never enter the canonical AST
        // E3 6.2 span pad 09 - comments never enter the canonical AST
        // E3 6.2 span pad 10 - comments never enter the canonical AST
        // E3 6.2 span pad 11 - comments never enter the canonical AST
        // E3 6.2 span pad 12 - comments never enter the canonical AST
        if ($score > 30) {
            $this->mailer->send('editor', $user);
        }
    }
}
