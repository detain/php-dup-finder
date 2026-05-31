<?php
declare(strict_types=1);

namespace App\User\Account;

use App\Domain\Entity\User;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Service\EmailServiceInterface;
use App\Domain\Service\AuthServiceInterface;
use App\Domain\Service\AnalyticsServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class AccountClosureWorkflow
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private EmailServiceInterface $emailService,
        private AuthServiceInterface $authService,
        private AnalyticsServiceInterface $analyticsService,
        private LoggerInterface $logger,
    ) {}

    public function initiateClosure(string $userId, string $reason): void
    {
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw new \RuntimeException("User not found: {$userId}");
        }

        $this->logger->info('Starting account closure workflow', ['user_id' => $userId]);

        $this->checkClosureEligibility($user);

        $this->checkForActiveSubscriptions($user);

        $this->checkForPendingOrders($user);

        $this->createClosureRequest($user, $reason);

        $this->sendClosureConfirmationEmail($user);

        $this->recordAuditEvent($user, 'account_closure_initiated');

        $this->logger->info('Account closure workflow completed', ['user_id' => $userId]);
    }

    private function checkClosureEligibility(User $user): void
    {
        if ($user->getStatus() === 'closed') {
            throw new \RuntimeException("Account is already closed");
        }

        if ($user->isLocked()) {
            throw new \RuntimeException("Account is locked. Please contact support");
        }

        $this->logger->debug('User eligible for closure', ['user_id' => $user->getId()->toString()]);
    }

    private function checkForActiveSubscriptions(User $user): void
    {
        $activeSubscriptions = $this->userRepository->countActiveSubscriptions($user->getId());

        if ($activeSubscriptions > 0) {
            throw new \RuntimeException("Please cancel all active subscriptions before closing your account");
        }

        $this->logger->debug('No active subscriptions found', ['user_id' => $user->getId()->toString()]);
    }

    private function checkForPendingOrders(User $user): void
    {
        $pendingOrders = $this->userRepository->countPendingOrders($user->getId());

        if ($pendingOrders > 0) {
            throw new \RuntimeException("Please complete or cancel pending orders before closing your account");
        }

        $this->logger->debug('No pending orders found', ['user_id' => $user->getId()->toString()]);
    }

    private function createClosureRequest(User $user, string $reason): void
    {
        $user->setClosureRequest([
            'reason' => $reason,
            'requested_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'status' => 'pending',
        ]);

        $user->setStatus('closure_pending');
        $this->userRepository->save($user);

        $this->logger->debug('Closure request created', ['user_id' => $user->getId()->toString()]);
    }

    private function sendClosureConfirmationEmail(User $user): void
    {
        $this->emailService->sendTemplate(
            $user->getEmail(),
            'account_closure_initiated',
            [
                'user_name' => $user->getFirstName(),
                'confirmation_link' => 'https://example.com/confirm-closure?user_id=' . $user->getId()->toString(),
                'cancel_link' => 'https://example.com/cancel-closure?user_id=' . $user->getId()->toString(),
            ]
        );

        $this->logger->debug('Closure confirmation email sent', ['user_id' => $user->getId()->toString()]);
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

    public function confirmClosure(string $userId): void
    {
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw new \RuntimeException("User not found: {$userId}");
        }

        $this->logger->info('Confirming account closure', ['user_id' => $userId]);

        $this->anonymizeUserData($user);

        $this->revokeTokens($user);

        $this->exportUserData($user);

        $this->closeAccount($user);

        $this->sendClosureCompleteEmail($user);

        $this->recordAuditEvent($user, 'account_closed');

        $this->logger->info('Account closure confirmed', ['user_id' => $userId]);
    }

    private function anonymizeUserData(User $user): void
    {
        $this->authService->anonymizePersonalData($user->getId()->toString());

        $user->setEmail("deleted_" . $user->getId()->toString() . "@example.com");
        $user->setFirstName('Deleted');
        $user->setLastName('User');

        $this->userRepository->save($user);

        $this->logger->debug('User data anonymized', ['user_id' => $user->getId()->toString()]);
    }

    private function revokeTokens(User $user): void
    {
        $this->authService->revokeAllTokens($user->getId()->toString());

        $this->logger->debug('Tokens revoked', ['user_id' => $user->getId()->toString()]);
    }

    private function exportUserData(User $user): void
    {
        $exportData = $this->authService->exportUserData($user->getId()->toString());

        $this->authService->storeDataExport($user->getId()->toString(), $exportData);

        $this->recordAuditEvent($user, 'data_exported');

        $this->logger->debug('User data exported', ['user_id' => $user->getId()->toString()]);
    }

    private function closeAccount(User $user): void
    {
        $user->setStatus('closed');
        $user->setClosedAt(new \DateTimeImmutable());
        $user->setUpdatedAt(new \DateTimeImmutable());

        $this->userRepository->save($user);

        $this->logger->debug('Account closed', ['user_id' => $user->getId()->toString()]);
    }

    private function sendClosureCompleteEmail(User $user): void
    {
        $this->emailService->sendTemplate(
            $user->getEmail(),
            'account_closed',
            [
                'closed_at' => $user->getClosedAt()->format('Y-m-d H:i:s'),
            ]
        );

        $this->logger->debug('Closure complete email sent', ['user_id' => $user->getId()->toString()]);
    }
}
