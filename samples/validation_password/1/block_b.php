<?php
declare(strict_types=1);

namespace Billing\Authentication;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;

final class PasswordResetService
{
    private const MIN_LENGTH = 8;
    private const MAX_LENGTH = 128;

    public function __construct(
        private readonly Connection $database,
        private readonly PasswordHasherFactory $hasherFactory,
        private readonly LoggerInterface $logger,
        private readonly TokenGenerator $tokenGenerator,
        private readonly EmailSender $emailSender
    ) {}

    public function requestReset(string $email): Result
    {
        $user = $this->database->fetchAssociative(
            'SELECT id, email, status FROM users WHERE email = ?',
            [strtolower($email)]
        );

        if ($user === false) {
            // Don't reveal whether email exists
            $this->logger->info('Password reset requested for unknown email', [
                'email' => substr($email, 0, 3) . '***'
            ]);
            return Result::success();
        }

        // Generate reset token
        $token = $this->tokenGenerator->generate();
        $expiresAt = (new \DateTimeImmutable())->modify('+1 hour');

        $this->database->executeStatement(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, ?)',
            [$user['id'], hash('sha256', $token), $expiresAt->format('Y-m-d H:i:s')]
        );

        $this->emailSender->sendPasswordResetEmail($user['email'], $token);

        $this->logger->info('Password reset token sent', [
            'user_id' => $user['id']
        ]);

        return Result::success();
    }

    public function resetPassword(string $token, string $newPassword): Result
    {
        // Validate password requirements
        $validationErrors = $this->checkPasswordStrength($newPassword);
        if (!empty($validationErrors)) {
            $this->logger->warning('Password reset failed: password too weak', [
                'errors' => $validationErrors
            ]);
            return Result::failure(implode('. ', $validationErrors));
        }

        // Find valid token
        $tokenHash = hash('sha256', $token);
        $resetData = $this->database->fetchAssociative(
            'SELECT user_id, expires_at FROM password_reset_tokens
             WHERE token_hash = ? AND used_at IS NULL',
            [$tokenHash]
        );

        if ($resetData === false) {
            $this->logger->warning('Password reset attempted with invalid token');
            return Result::failure('Invalid or expired reset token');
        }

        // Check expiration
        $expiresAt = new \DateTimeImmutable($resetData['expires_at']);
        if ($expiresAt < new \DateTimeImmutable()) {
            $this->logger->warning('Password reset attempted with expired token');
            return Result::failure('Invalid or expired reset token');
        }

        // Update password
        $hasher = $this->hasherFactory->getPasswordHasher('common');
        $hashedPassword = $hasher->hash($newPassword);

        $this->database->executeStatement(
            'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?',
            [$hashedPassword, $resetData['user_id']]
        );

        // Mark token as used
        $this->database->executeStatement(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE token_hash = ?',
            [$tokenHash]
        );

        $this->logger->info('Password reset completed', [
            'user_id' => $resetData['user_id']
        ]);

        return Result::success();
    }

    private function checkPasswordStrength(string $password): array
    {
        $issues = [];

        $length = strlen($password);
        if ($length < self::MIN_LENGTH) {
            $issues[] = sprintf('Password must be at least %d characters', self::MIN_LENGTH);
        }
        if ($length > self::MAX_LENGTH) {
            $issues[] = sprintf('Password must not exceed %d characters', self::MAX_LENGTH);
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $issues[] = 'Password must contain at least one uppercase letter';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $issues[] = 'Password must contain at least one lowercase letter';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $issues[] = 'Password must contain at least one number';
        }
        if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            $issues[] = 'Password must contain at least one special character';
        }

        // Reject common passwords
        $forbidden = ['password', '12345678', 'qwerty123', 'letmein', 'welcome123'];
        foreach ($forbidden as $forbiddenPwd) {
            if (str_contains(strtolower($password), $forbiddenPwd)) {
                $issues[] = 'Password contains a common word or pattern';
                break;
            }
        }

        // No sequential characters
        if (preg_match('/(?:abc|bcd|cde|def|efg|fgh|ghi|hij|ijk|jkl|klm|lmn|mno|nop|opq|pqr|qrs|rst|stu|tuv|uvw|vwx|wxy|xyz|012|123|234|345|456|567|678|789)/i', $password)) {
            $issues[] = 'Password cannot contain sequential characters';
        }

        return $issues;
    }
}
