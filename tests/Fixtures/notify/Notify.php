<?php
namespace Fixtures\Notify;

class Mailer { public function send(string $role, $user): void {} }

class Notify
{
    public function __construct(private Mailer $mailer) {}

    public function notifyHigh($user, int $score): void
    {
        if ($score > 10) {
            $this->mailer->send('admin', $user);
        }
    }

    public function notifyMid($user, int $score): void
    {
        if ($score > 20) {
            $this->mailer->send('moderator', $user);
        }
    }

    public function notifyLow($user, int $score): void
    {
        if ($score > 30) {
            $this->mailer->send('editor', $user);
        }
    }
}
