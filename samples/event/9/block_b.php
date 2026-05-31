<?php
declare(strict_types=1);

namespace App\Account\Handlers;

use App\Entity\UserAccount;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\SessionService;
use App\Service\NotificationService;
use App\Service\CleanupService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class AccountDeactivatedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly SessionService $sessionService,
        private readonly NotificationService $notificationService,
        private readonly CleanupService $cleanupService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(UserAccount $account, string $reason, ?int $deactivatedBy): void
    {
        $this->logger->info('Processing account deactivated event', [
            'account_id' => $account->getId(),
            'user_id' => $account->getUserId(),
            'reason' => $reason,
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->deactivateAccount($account, $reason);
            $this->invalidateAllSessions($account);
            $this->revokeAllTokens($account);
            $this->disableLinkedServices($account);
            $this->archiveAccountData($account);
            $this->sendDeactivationNotice($account);
            $this->recordDeactivationMetrics($account, $reason);
            $this->createAuditEntry($account, $reason, $deactivatedBy);
            $this->schedulePermanentDeletion($account);

            $this->entityManager->commit();

            $this->logger->info('Account deactivated event processed', [
                'account_id' => $account->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process account deactivated event', [
                'account_id' => $account->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function deactivateAccount(UserAccount $account, string $reason): void
    {
        $account->setStatus('deactivated');
        $account->setDeactivationReason($reason);
        $account->setDeactivatedAt(new \DateTimeImmutable());
        $account->setActive(false);

        $this->entityManager->persist($account);

        $this->logger->debug('Deactivated account', [
            'account_id' => $account->getId(),
            'reason' => $reason,
        ]);
    }

    private function invalidateAllSessions(UserAccount $account): void
    {
        $sessions = $this->entityManager
            ->getRepository(\App\Entity\UserSession::class)
            ->findActiveByUser($account->getUserId());

        foreach ($sessions as $session) {
            $session->setStatus('deactivated');
            $session->setInvalidatedAt(new \DateTimeImmutable());
            $session->setInvalidationReason('account_deactivated');

            $this->entityManager->persist($session);

            $this->queueService->publish('session.invalidate', [
                'session_id' => $session->getId(),
                'user_id' => $account->getUserId(),
                'reason' => 'account_deactivated',
            ]);
        }

        $this->logger->debug('Invalidated all sessions', [
            'account_id' => $account->getId(),
            'session_count' => count($sessions),
        ]);
    }

    private function revokeAllTokens(UserAccount $account): void
    {
        $tokens = $this->entityManager
            ->getRepository(\App\Entity\RefreshToken::class)
            ->findActiveByUser($account->getUserId());

        foreach ($tokens as $token) {
            $token->setStatus('revoked');
            $token->setRevokedAt(new \DateTimeImmutable());
            $token->setRevocationReason('account_deactivated');

            $this->entityManager->persist($token);
        }

        $apiKeys = $this->entityManager
            ->getRepository(\App\Entity\ApiKey::class)
            ->findActiveByUser($account->getUserId());

        foreach ($apiKeys as $key) {
            $key->setStatus('deactivated');
            $key->setDeactivatedAt(new \DateTimeImmutable());
            $this->entityManager->persist($key);
        }

        $this->queueService->publish('tokens.revoke_all', [
            'user_id' => $account->getUserId(),
            'reason' => 'account_deactivated',
        ]);

        $this->logger->debug('Revoked all tokens and API keys', [
            'account_id' => $account->getId(),
            'token_count' => count($tokens),
            'api_key_count' => count($apiKeys),
        ]);
    }

    private function disableLinkedServices(UserAccount $account): void
    {
        $linkedServices = $this->entityManager
            ->getRepository(\App\Entity\LinkedService::class)
            ->findByUser($account->getUserId());

        foreach ($linkedServices as $service) {
            $service->setStatus('inactive');
            $service->setDeactivatedAt(new \DateTimeImmutable());
            $service->setDeactivationReason('account_deactivated');

            $this->entityManager->persist($service);

            $this->queueService->publish('service.disable', [
                'service_id' => $service->getId(),
                'user_id' => $account->getUserId(),
                'reason' => 'account_deactivated',
            ]);
        }

        $this->logger->debug('Disabled linked services', [
            'account_id' => $account->getId(),
            'service_count' => count($linkedServices),
        ]);
    }

    private function archiveAccountData(UserAccount $account): void
    {
        $archive = new \App\Entity\AccountArchive();
        $archive->setAccountId($account->getId());
        $archive->setUserId($account->getUserId());
        $archive->setArchivedAt(new \DateTimeImmutable());
        $archive->setArchiveType('deactivation_backup');

        $user = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->find($account->getUserId());

        $archiveData = [
            'email' => $user?->getEmail(),
            'display_name' => $user?->getDisplayName(),
            'created_at' => $account->getCreatedAt()?->format(\DATE_ATOM),
            'deactivation_reason' => $account->getDeactivationReason(),
            'data_retention_until' => (new \DateTimeImmutable())->modify('+90 days')->format(\DATE_ATOM),
        ];

        $archive->setArchiveData(json_encode($archiveData));
        $this->entityManager->persist($archive);

        $this->queueService->publish('data.archive.deactivation', [
            'archive_id' => $archive->getId(),
            'user_id' => $account->getUserId(),
        ]);

        $this->logger->debug('Archived account data', [
            'account_id' => $account->getId(),
            'archive_id' => $archive->getId(),
        ]);
    }

    private function sendDeactivationNotice(UserAccount $account): void
    {
        $user = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->find($account->getUserId());

        if ($user === null || $user->getEmail() === null) {
            return;
        }

        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'account_deactivated']);

        if ($template === null) {
            return;
        }

        $this->queueService->publish('email.outbound', [
            'template_id' => $template->getId(),
            'recipient' => $user->getEmail(),
            'variables' => [
                'user_name' => $user->getDisplayName(),
                'deactivated_at' => $account->getDeactivatedAt()->format('Y-m-d H:i:s'),
                'reactivation_url' => '/account/reactivate',
                'data_deletion_date' => (new \DateTimeImmutable())->modify('+90 days')->format('Y-m-d'),
            ],
            'priority' => 'normal',
        ]);

        $this->logger->debug('Sent deactivation notice', [
            'account_id' => $account->getId(),
        ]);
    }

    private function recordDeactivationMetrics(UserAccount $account, string $reason): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('account_deactivated');
        $analyticsEvent->setCustomerId($account->getUserId());
        $analyticsEvent->setPayload([
            'account_id' => $account->getId(),
            'reason' => $reason,
            'account_age_days' => $account->getCreatedAt()
                ? (new \DateTimeImmutable())->diff($account->getCreatedAt())->days
                : 0,
            'lifetime_value' => $account->getLifetimeValue(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded deactivation metrics', [
            'account_id' => $account->getId(),
        ]);
    }

    private function createAuditEntry(UserAccount $account, string $reason, ?int $deactivatedBy): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('ACCOUNT_DEACTIVATED');
        $auditEntry->setEntityType('user_account');
        $auditEntry->setEntityId($account->getId());
        $auditEntry->setUserId($deactivatedBy ?? $account->getUserId());
        $auditEntry->setMetadata([
            'user_id' => $account->getUserId(),
            'reason' => $reason,
            'deactivated_at' => $account->getDeactivatedAt()->format(\DATE_ATOM),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit entry', [
            'account_id' => $account->getId(),
        ]);
    }

    private function schedulePermanentDeletion(UserAccount $account): void
    {
        $deletionDate = (new \DateTimeImmutable())->modify('+90 days');

        $deletionJob = new \App\Entity\ScheduledDeletion();
        $deletionJob->setAccountId($account->getId());
        $deletionJob->setUserId($account->getUserId());
        $deletionJob->setScheduledFor($deletionDate);
        $deletionJob->setStatus('pending');
        $deletionJob->setReason('user_requested');
        $deletionJob->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($deletionJob);

        $this->queueService->publish('jobs.schedule_deletion', [
            'job_id' => $deletionJob->getId(),
            'user_id' => $account->getUserId(),
            'scheduled_for' => $deletionDate->format(\DATE_ATOM),
        ]);

        $this->logger->debug('Scheduled permanent deletion', [
            'account_id' => $account->getId(),
            'deletion_date' => $deletionDate->format(\DATE_ATOM),
        ]);
    }
}
