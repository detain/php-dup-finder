<?php
declare(strict_types=1);

namespace App\Account\Handlers;

use App\Entity\UserAccount;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\SessionService;
use App\Service\NotificationService;
use App\Service\AnonymizationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class AccountDeletedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly SessionService $sessionService,
        private readonly NotificationService $notificationService,
        private readonly AnonymizationService $anonymizationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(UserAccount $account, string $reason, int $deletedBy): void
    {
        $this->logger->info('Processing account deleted event', [
            'account_id' => $account->getId(),
            'user_id' => $account->getUserId(),
            'reason' => $reason,
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->markAccountDeleted($account, $reason);
            $this->terminateAllSessions($account);
            $this->destroyAllTokens($account);
            $this->anonymizePersonalData($account);
            $this->removeAccountAssociations($account);
            $this->sendDeletionConfirmation($account);
            $this->notifyThirdPartyServices($account);
            $this->recordDeletionMetrics($account, $reason, $deletedBy);
            $this->createAuditEntry($account, $reason, $deletedBy);
            $this->cleanupRelatedRecords($account);

            $this->entityManager->commit();

            $this->logger->info('Account deleted event processed', [
                'account_id' => $account->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process account deleted event', [
                'account_id' => $account->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function markAccountDeleted(UserAccount $account, string $reason): void
    {
        $account->setStatus('deleted');
        $account->setDeletionReason($reason);
        $account->setDeletedAt(new \DateTimeImmutable());
        $account->setDeletedBy($reason === 'user_requested' ? $account->getUserId() : 0);
        $account->setActive(false);

        $this->entityManager->persist($account);

        $this->logger->debug('Marked account as deleted', [
            'account_id' => $account->getId(),
            'reason' => $reason,
        ]);
    }

    private function terminateAllSessions(UserAccount $account): void
    {
        $sessions = $this->entityManager
            ->getRepository(\App\Entity\UserSession::class)
            ->findAllByUser($account->getUserId());

        foreach ($sessions as $session) {
            $session->setStatus('deleted');
            $session->setInvalidatedAt(new \DateTimeImmutable());
            $session->setInvalidationReason('account_deleted');

            $this->entityManager->persist($session);

            $this->queueService->publish('session.terminate', [
                'session_id' => $session->getId(),
                'user_id' => $account->getUserId(),
                'reason' => 'account_deleted',
            ]);
        }

        $this->logger->debug('Terminated all sessions', [
            'account_id' => $account->getId(),
            'session_count' => count($sessions),
        ]);
    }

    private function destroyAllTokens(UserAccount $account): void
    {
        $refreshTokens = $this->entityManager
            ->getRepository(\App\Entity\RefreshToken::class)
            ->findByUser($account->getUserId());

        foreach ($refreshTokens as $token) {
            $token->setStatus('destroyed');
            $token->setRevokedAt(new \DateTimeImmutable());
            $token->setRevocationReason('account_deleted');

            $this->entityManager->persist($token);
        }

        $accessTokens = $this->entityManager
            ->getRepository(\App\Entity\AccessToken::class)
            ->findByUser($account->getUserId());

        foreach ($accessTokens as $token) {
            $token->setStatus('destroyed');
            $token->setRevokedAt(new \DateTimeImmutable());
            $this->entityManager->persist($token);
        }

        $apiKeys = $this->entityManager
            ->getRepository(\App\Entity\ApiKey::class)
            ->findByUser($account->getUserId());

        foreach ($apiKeys as $key) {
            $key->setStatus('destroyed');
            $key->setDeactivatedAt(new \DateTimeImmutable());
            $this->entityManager->persist($key);
        }

        $this->logger->debug('Destroyed all tokens', [
            'account_id' => $account->getId(),
            'refresh_token_count' => count($refreshTokens),
            'access_token_count' => count($accessTokens),
            'api_key_count' => count($apiKeys),
        ]);
    }

    private function anonymizePersonalData(UserAccount $account): void
    {
        $user = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->find($account->getUserId());

        if ($user !== null) {
            $anonymizedId = 'deleted_user_' . hash('sha256', (string) $account->getId());

            $user->setEmail($anonymizedId . '@deleted.local');
            $user->setDisplayName('Deleted User');
            $user->setPhone(null);
            $user->setAvatarUrl(null);
            $user->setFirstName(null);
            $user->setLastName(null);
            $user->setDateOfBirth(null);
            $user->setAddressLine1(null);
            $user->setAddressLine2(null);
            $user->setCity(null);
            $user->setState(null);
            $user->setPostalCode(null);
            $user->setCountry(null);
            $user->setAnonymized(true);
            $user->setAnonymizedAt(new \DateTimeImmutable());

            $this->entityManager->persist($user);
        }

        $this->queueService->publish('data.anonymize', [
            'user_id' => $account->getUserId(),
            'anonymized_id' => $anonymizedId ?? 'unknown',
        ]);

        $this->logger->debug('Anonymized personal data', [
            'account_id' => $account->getId(),
        ]);
    }

    private function removeAccountAssociations(UserAccount $account): void
    {
        $accounts = $this->entityManager
            ->getRepository(\App\Entity\AccountAssociation::class)
            ->findByUser($account->getUserId());

        foreach ($accounts as $association) {
            $association->setStatus('terminated');
            $association->setTerminatedAt(new \DateTimeImmutable());
            $association->setTerminationReason('account_deleted');

            $this->entityManager->persist($association);

            $this->queueService->publish('service.dissociate', [
                'association_id' => $association->getId(),
                'user_id' => $account->getUserId(),
            ]);
        }

        $this->logger->debug('Removed account associations', [
            'account_id' => $account->getId(),
            'association_count' => count($accounts),
        ]);
    }

    private function sendDeletionConfirmation(UserAccount $account): void
    {
        $user = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->find($account->getUserId());

        if ($user === null || !filter_var($user->getEmail(), FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'account_deleted']);

        if ($template === null) {
            return;
        }

        $this->queueService->publish('email.outbound', [
            'template_id' => $template->getId(),
            'recipient' => $user->getEmail(),
            'variables' => [
                'deleted_at' => $account->getDeletedAt()->format('Y-m-d H:i:s'),
                'confirmation_number' => 'DEL-' . strtoupper(bin2hex(random_bytes(6))),
            ],
            'priority' => 'normal',
        ]);

        $this->logger->debug('Sent deletion confirmation', [
            'account_id' => $account->getId(),
        ]);
    }

    private function notifyThirdPartyServices(UserAccount $account): void
    {
        $connectedApps = $this->entityManager
            ->getRepository(\App\Entity\ConnectedApp::class)
            ->findByUser($account->getUserId());

        foreach ($connectedApps as $app) {
            $this->queueService->publish('oauth.revoke_user', [
                'client_id' => $app->getClientId(),
                'user_id' => $account->getUserId(),
                'reason' => 'account_deleted',
            ]);
        }

        $this->logger->debug('Notified third-party services', [
            'account_id' => $account->getId(),
            'app_count' => count($connectedApps),
        ]);
    }

    private function recordDeletionMetrics(UserAccount $account, string $reason, int $deletedBy): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('account_deleted');
        $analyticsEvent->setCustomerId($account->getUserId());
        $analyticsEvent->setPayload([
            'account_id' => $account->getId(),
            'reason' => $reason,
            'account_age_days' => $account->getCreatedAt()
                ? (new \DateTimeImmutable())->diff($account->getCreatedAt())->days
                : 0,
            'was_self_deleted' => $reason === 'user_requested',
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded deletion metrics', [
            'account_id' => $account->getId(),
        ]);
    }

    private function createAuditEntry(UserAccount $account, string $reason, int $deletedBy): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('ACCOUNT_DELETED');
        $auditEntry->setEntityType('user_account');
        $auditEntry->setEntityId($account->getId());
        $auditEntry->setUserId($deletedBy);
        $auditEntry->setMetadata([
            'user_id' => $account->getUserId(),
            'reason' => $reason,
            'deleted_at' => $account->getDeletedAt()->format(\DATE_ATOM),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit entry', [
            'account_id' => $account->getId(),
        ]);
    }

    private function cleanupRelatedRecords(UserAccount $account): void
    {
        $this->queueService->publish('data.cleanup.user_records', [
            'user_id' => $account->getUserId(),
            'account_id' => $account->getId(),
            'cleanup_types' => ['activity_logs', 'preferences', 'analytics'],
        ]);

        $this->logger->debug('Scheduled cleanup of related records', [
            'account_id' => $account->getId(),
        ]);
    }
}
