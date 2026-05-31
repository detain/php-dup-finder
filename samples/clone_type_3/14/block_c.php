<?php

declare(strict_types=1);

namespace App\Processor;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailService;
use App\Service\AuditService;
use Psr\Log\LoggerInterface;

final class UserBatchProcessor
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EmailService $emailService,
        private readonly AuditService $auditService,
        private readonly LoggerInterface $logger,
    ) {}

    public function processPasswordResets(array $userIds): ProcessingResult
    {
        $processed = 0;
        $failed = [];
        $users = $this->userRepository->findByIds($userIds);

        foreach ($users as $user) {
            try {
                $this->validateUserForPasswordReset($user);

                $resetToken = $user->generatePasswordResetToken();
                $user->setPasswordResetToken($resetToken);
                $user->setPasswordResetExpiresAt(new \DateTime('+24 hours'));

                $this->userRepository->save($user);

                $this->emailService->sendPasswordResetEmail($user, $resetToken);

                $this->auditService->log('password_reset_initiated', [
                    'user_id' => $user->getId(),
                ]);

                $this->logger->info('Password reset processed', [
                    'user_id' => $user->getId(),
                ]);

                $processed++;
            } catch (\Exception $e) {
                $failed[] = [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                ];

                $this->logger->error('Failed to process password reset', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new ProcessingResult($processed, count($failed), $failed);
    }

    public function processSuspensions(array $userIds, string $reason): ProcessingResult
    {
        $processed = 0;
        $failed = [];
        $users = $this->userRepository->findByIds($userIds);

        foreach ($users as $user) {
            try {
                $this->validateUserForSuspension($user);

                $user->suspend($reason);

                $this->userRepository->save($user);

                $this->emailService->sendSuspensionNotification($user, $reason);

                $this->auditService->log('user_suspended', [
                    'user_id' => $user->getId(),
                    'reason' => $reason,
                ]);

                $this->logger->info('User suspended', [
                    'user_id' => $user->getId(),
                    'reason' => $reason,
                ]);

                $processed++;
            } catch (\Exception $e) {
                $failed[] = [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                ];

                $this->logger->error('Failed to suspend user', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new ProcessingResult($processed, count($failed), $failed);
    }

    public function processVerifications(array $userIds): ProcessingResult
    {
        $processed = 0;
        $failed = [];
        $users = $this->userRepository->findByIds($userIds);

        foreach ($users as $user) {
            try {
                $this->validateUserForVerification($user);

                $user->markAsVerified();
                $user->setEmailVerifiedAt(new \DateTime());

                $this->userRepository->save($user);

                $this->emailService->sendVerificationConfirmation($user);

                $this->auditService->log('email_verified', [
                    'user_id' => $user->getId(),
                ]);

                $this->logger->info('User verified', [
                    'user_id' => $user->getId(),
                ]);

                $processed++;
            } catch (\Exception $e) {
                $failed[] = [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                ];

                $this->logger->error('Failed to verify user', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new ProcessingResult($processed, count($failed), $failed);
    }

    private function validateUserForPasswordReset(User $user): void
    {
        if (!$user->canResetPassword()) {
            throw new \RuntimeException('User cannot reset password in current status: ' . $user->getStatus());
        }
    }

    private function validateUserForSuspension(User $user): void
    {
        if (!$user->canBeSuspended()) {
            throw new \RuntimeException('User cannot be suspended in current status: ' . $user->getStatus());
        }
    }

    private function validateUserForVerification(User $user): void
    {
        if (!$user->canBeVerified()) {
            throw new \RuntimeException('User cannot be verified in current status: ' . $user->getStatus());
        }
    }
}
