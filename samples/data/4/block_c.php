<?php
declare(strict_types=1);

namespace App\Auth\PasswordReset;

use App\Database\Connection;
use App\Notifications\Mailer;

final class PasswordResetService
{
    public function __construct(
        private Connection $db,
        private Mailer $mailer,
    ) {
    }

    public function completeReset(string $token, string $newPassword): bool
    {
        if (strlen($newPassword) < 12) {
            throw new \InvalidArgumentException('Password too short');
        }

        $reset = $this->db->fetchOne(
            'SELECT id, user_id, expires_at, consumed_at FROM password_resets WHERE token = ?',
            [hash('sha256', $token)]
        );

        if ($reset === null) {
            throw new \DomainException('Invalid reset token');
        }

        if ($reset['consumed_at'] !== null) {
            throw new \DomainException('Reset token already used');
        }

        if (strtotime((string)$reset['expires_at']) < time()) {
            throw new \DomainException('Reset token expired');
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($newHash === false) {
            throw new \RuntimeException('Hashing failed');
        }

        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?',
                [$newHash, (int)$reset['user_id']]
            );

            $this->db->execute(
                'UPDATE password_resets SET consumed_at = NOW() WHERE id = ?',
                [(int)$reset['id']]
            );

            $this->db->execute(
                'DELETE FROM user_sessions WHERE user_id = ?',
                [(int)$reset['user_id']]
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $user = $this->db->fetchOne('SELECT email FROM users WHERE id = ?', [(int)$reset['user_id']]);
        $this->mailer->send($user['email'], 'Password changed', 'Your password was changed.');

        return true;
    }
}
