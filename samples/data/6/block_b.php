<?php
declare(strict_types=1);

namespace App\Notifications\Handlers;

use App\Events\PasswordChanged;
use App\Mail\MailerInterface;
use App\Database\Connection;

final class PasswordChangedHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private Connection $db,
    ) {
    }

    public function handle(PasswordChanged $event): void
    {
        $user = $this->db->fetchOne(
            'SELECT id, email, name, last_login_ip FROM users WHERE id = ?',
            [$event->userId]
        );

        if ($user === null) {
            throw new \RuntimeException('User not found for password-changed event: ' . $event->userId);
        }

        $changedAt = $event->changedAt->format('Y-m-d H:i:s T');

        $html = "<html><body>";
        $html .= "<p>Hi {$user['name']},</p>";
        $html .= "<p>Your password was just changed at {$changedAt}.</p>";
        $html .= "<p>If this wasn't you, please contact support immediately.</p>";
        $html .= "<p>For your records, the change was initiated from IP: " . htmlspecialchars((string)$user['last_login_ip']) . "</p>";
        $html .= "</body></html>";

        $textFallback = "Your password was changed at {$changedAt}. " .
            "If this wasn't you, contact support.";

        $this->mailer->send([
            'from'      => 'noreply@example.com',
            'reply_to'  => 'security@example.com',
            'to'        => $user['email'],
            'subject'   => 'Security alert: password changed',
            'html_body' => $html,
            'text_body' => $textFallback,
            'headers'   => [
                'X-Auto-Response-Suppress' => 'OOF, AutoReply',
            ],
        ]);

        $this->db->execute(
            'INSERT INTO security_audit (user_id, event, payload, created_at) VALUES (?, ?, ?, NOW())',
            [(int)$user['id'], 'password_changed_email', json_encode(['changed_at' => $changedAt])]
        );
    }
}
