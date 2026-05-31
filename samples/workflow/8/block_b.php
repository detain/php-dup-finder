<?php
declare(strict_types=1);

namespace App\User\Account;

use App\Domain\Entity\User;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Service\EmailServiceInterface;
use App\Domain\Service\AuthServiceInterface;
use App\Domain\Service\AnalyticsServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class PasswordResetWorkflow
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private EmailServiceInterface $emailService,
        private AuthServiceInterface $authService,
        private AnalyticsServiceInterface $analyticsService,
        private LoggerInterface $logger,
    ) {}

    public function initiateReset(string $email): void
    {
        $this->logger->info('Starting password reset workflow', ['email' => $email]);

        $user = $this->findUser($email);

        if ($user !== null) {
            $this->checkResetEligibility($user);

            $this->generateResetToken($user);

            $this->sendResetEmail($user);

            $this->recordAuditEvent($user, 'password_reset_initiated');
        } else {
            $this->handleUnknownEmail($email);
        }

        $this->logger->info('Password reset workflow completed', ['email' => $email]);
    }

    private function findUser(string $email): ?User
    {
        $user = $this->userRepository->findByEmail($email);

        if ($user === null) {
            $this->logger->debug('User not found for password reset', ['email' => $email]);
        }

        return $user;
    }

    private function checkResetEligibility(User $user): void
    {
        if (!$user->isActive()) {
            throw new \RuntimeException("Cannot reset password for inactive account");
        }

        if ($user->isLocked()) {
            throw new \RuntimeException("Account is locked. Please contact support");
        }

        if ($user->hasRecentPasswordReset()) {
            throw new \RuntimeException("Password reset already requested recently");
        }

        $this->logger->debug('User eligible for password reset', ['user_id' => $user->getId()->toString()]);
    }

    private function generateResetToken(User $user): void
    {
        $token = $this->authService->generatePasswordResetToken($user->getId()->toString());

        $user->setPasswordResetToken($token);
        $user->setPasswordResetRequestedAt(new \DateTimeImmutable());

        $this->userRepository->save($user);

        $this->logger->debug('Reset token generated', ['user_id' => $user->getId()->toString()]);
    }

    private function sendResetEmail(User $user): void
    {
        $this->emailService->sendTemplate(
            $user->getEmail(),
            'password_reset',
            [
                'user_name' => $user->getFirstName(),
                'reset_link' => "https://example.com/reset-password?token={$user->getPasswordResetToken()}",
                'expires_in_minutes' => 60,
            ]
        );

        $this->logger->debug('Reset email sent', ['user_id' => $user->getId()->toString()]);
    }

    private function handleUnknownEmail(string $email): void
    {
        $this->logger->debug('Unknown email for password reset', ['email' => $email]);
    }

    private function recordAuditEvent(User $user, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'user_id' => $user->getId()->toString(),
            'email' => $user->getEmail(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }

    public function completeReset(string $token, string $newPassword): void
    {
        $this->logger->info('Starting password reset completion', []);

        $user = $this->userRepository->findByPasswordResetToken($token);
        if ($user === null) {
            throw new \RuntimeException("Invalid or expired reset token");
        }

        $this->validateResetToken($user);

        $this->validateNewPassword($newPassword);

        $this->updatePassword($user, $newPassword);

        $this->invalidateResetToken($user);

        $this->sendConfirmationEmail($user);

        $this->recordAuditEvent($user, 'password_reset_completed');

        $this->logger->info('Password reset workflow completed', ['user_id' => $user->getId()->toString()]);
    }

    private function validateResetToken(User $user): void
    {
        $requestedAt = $user->getPasswordResetRequestedAt();
        if ($requestedAt === null) {
            throw new \RuntimeException("Invalid reset token");
        }

        $expiresAt = $requestedAt->modify('+1 hour');
        if (new \DateTimeImmutable() > $expiresAt) {
            throw new \RuntimeException("Reset token has expired");
        }

        $this->logger->debug('Reset token validated', ['user_id' => $user->getId()->toString()]);
    }

    private function validateNewPassword(string $newPassword): void
    {
        if (strlen($newPassword) < 8) {
            throw new \RuntimeException("Password must be at least 8 characters");
        }
    }

    private function updatePassword(User $user, string $newPassword): void
    {
        $hashedPassword = $this->authService->hashPassword($newPassword);
        $user->setPasswordHash($hashedPassword);
        $user->setUpdatedAt(new \DateTimeImmutable());

        $this->userRepository->save($user);

        $this->logger->debug('Password updated', ['user_id' => $user->getId()->toString()]);
    }

    private function invalidateResetToken(User $user): void
    {
        $user->setPasswordResetToken(null);
        $user->setPasswordResetRequestedAt(null);

        $this->userRepository->save($user);

        $this->logger->debug('Reset token invalidated', ['user_id' => $user->getId()->toString()]);
    }

    private function sendConfirmationEmail(User $user): void
    {
        $this->emailService->sendTemplate(
            $user->getEmail(),
            'password_reset_complete',
            [
                'user_name' => $user->getFirstName(),
                'changed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'support_link' => 'https://example.com/support',
            ]
        );

        $this->logger->debug('Confirmation email sent', ['user_id' => $user->getId()->toString()]);
    }
}
