<?php
declare(strict_types=1);

namespace Billing\UserProfile;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherInterface;

final class ProfileUpdateHandler
{
    private const PASSWORD_MIN_LENGTH = 8;
    private const PASSWORD_MAX_LENGTH = 128;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger
    ) {}

    public function updatePassword(User $user, array $data): UpdateResult
    {
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';

        // Verify current password
        if (!$this->passwordHasher->verify($user->getPasswordHash(), $currentPassword)) {
            $this->logger->warning('Password change failed: invalid current password', [
                'user_id' => $user->getId()
            ]);
            return UpdateResult::failure('Current password is incorrect');
        }

        // Validate new password strength
        $passwordProblems = $this->assessPasswordRobustness($newPassword);
        if (!empty($passwordProblems)) {
            $this->logger->info('Password change blocked: insufficient strength', [
                'user_id' => $user->getId(),
                'issues' => $passwordProblems
            ]);
            return UpdateResult::failure($this->formatPasswordErrors($passwordProblems));
        }

        // Verify confirmation matches
        if ($newPassword !== $confirmPassword) {
            return UpdateResult::failure('New password and confirmation do not match');
        }

        // Check password history (last 5 passwords)
        if ($this->isPasswordReused($user, $newPassword)) {
            return UpdateResult::failure('Cannot reuse any of your last 5 passwords');
        }

        // Update password
        $user->setPasswordHash($this->passwordHasher->hash($newPassword));
        $user->setPasswordChangedAt(new \DateTimeImmutable());
        $user->setPasswordHistory($this->updatePasswordHistory($user, $newPassword));

        $this->entityManager->flush();

        $this->logger->info('Password updated successfully', ['user_id' => $user->getId()]);

        // Invalidate all sessions except current
        $this->invalidateOtherSessions($user);

        return UpdateResult::success('Password updated successfully');
    }

    private function assessPasswordRobustness(string $password): array
    {
        $failures = [];

        $len = mb_strlen($password);
        if ($len < self::PASSWORD_MIN_LENGTH) {
            $failures[] = 'Password must be at least ' . self::PASSWORD_MIN_LENGTH . ' characters';
        }
        if ($len > self::PASSWORD_MAX_LENGTH) {
            $failures[] = 'Password must not exceed ' . self::PASSWORD_MAX_LENGTH . ' characters';
        }

        if (!preg_match('/[A-Z]/u', $password)) {
            $failures[] = 'Must contain at least one uppercase letter';
        }
        if (!preg_match('/[a-z]/u', $password)) {
            $failures[] = 'Must contain at least one lowercase letter';
        }
        if (!preg_match('/[0-9]/u', $password)) {
            $failures[] = 'Must contain at least one number';
        }
        if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'"\\|,.<>\/?]/u', $password)) {
            $failures[] = 'Must contain at least one special character';
        }

        // Check for personal info patterns
        if (preg_match('/^(.)\1{2,}$/u', $password)) {
            $failures[] = 'Cannot contain 3+ repeated characters';
        }

        // Check for date patterns (mm/dd, yyyy, etc)
        if (preg_match('/\d{2}[\/\-]\d{2}[\/\-]?\d{0,4}/', $password)) {
            $failures[] = 'Cannot contain date-like patterns';
        }

        return $failures;
    }

    private function isPasswordReused(User $user, string $newPassword): bool
    {
        $history = $user->getPasswordHistory() ?? [];
        foreach (array_slice($history, -5) as $oldHash) {
            if ($this->passwordHasher->verify($oldHash, $newPassword)) {
                return true;
            }
        }
        return false;
    }

    private function updatePasswordHistory(User $user, string $newPassword): array
    {
        $history = $user->getPasswordHistory() ?? [];
        $history[] = $user->getPasswordHash();
        return array_slice($history, -10);
    }

    private function formatPasswordErrors(array $errors): string
    {
        return implode(' ', array_map(fn($e) => '- ' . $e, $errors));
    }

    private function invalidateOtherSessions(User $user): void
    {
        // Implementation for session invalidation
    }
}
