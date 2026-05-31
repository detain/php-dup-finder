<?php
declare(strict_types=1);

namespace App\Account;

use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

trait AccountEventHandlerTrait
{
    abstract protected function getEntityManager(): EntityManagerInterface;
    abstract protected function getQueueService(): QueueService;
    abstract protected function getLogger(): LoggerInterface;

    protected function executeAccountEvent(callable $operations, array $context): void
    {
        $this->getLogger()->info('Processing account event', $context);

        $em = $this->getEntityManager();
        $em->beginTransaction();

        try {
            $operations();
            $em->commit();
        } catch (\Throwable $e) {
            $em->rollback();
            $this->getLogger()->error('Account event failed', array_merge($context, [
                'error' => $e->getMessage(),
            ]));
            throw $e;
        }
    }

    protected function invalidateAllUserSessions(int $userId, string $reason): void
    {
        $sessions = $this->getEntityManager()
            ->getRepository(\App\Entity\UserSession::class)
            ->findActiveByUser($userId);

        foreach ($sessions as $session) {
            $session->setStatus('invalidated');
            $session->setInvalidatedAt(new \DateTimeImmutable());
            $session->setInvalidationReason($reason);
            $this->getEntityManager()->persist($session);

            $this->getQueueService()->publish('session.invalidate', [
                'session_id' => $session->getId(),
                'user_id' => $userId,
                'reason' => $reason,
            ]);
        }
    }

    protected function revokeAllUserTokens(int $userId, string $reason): void
    {
        $tokens = $this->getEntityManager()
            ->getRepository(\App\Entity\RefreshToken::class)
            ->findActiveByUser($userId);

        foreach ($tokens as $token) {
            $token->setStatus('revoked');
            $token->setRevokedAt(new \DateTimeImmutable());
            $token->setRevocationReason($reason);
            $this->getEntityManager()->persist($token);
        }

        $this->getQueueService()->publish('tokens.revoke_all', [
            'user_id' => $userId,
            'reason' => $reason,
        ]);
    }

    protected function recordAccountAnalytics(string $eventName, int $userId, array $payload): void
    {
        $event = new AnalyticsEvent();
        $event->setEventName($eventName);
        $event->setCustomerId($userId);
        $event->setPayload($payload);
        $event->setOccurredAt(new \DateTimeImmutable());
        $this->getEntityManager()->persist($event);
    }

    protected function createAccountAuditEntry(string $action, int $entityId, int $userId, array $metadata): void
    {
        $entry = new AuditLog();
        $entry->setAction($action);
        $entry->setEntityType('user_account');
        $entry->setEntityId($entityId);
        $entry->setUserId($userId);
        $entry->setMetadata($metadata);
        $entry->setCreatedAt(new \DateTimeImmutable());
        $this->getEntityManager()->persist($entry);
    }
}
