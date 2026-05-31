<?php
declare(strict_types=1);

namespace App\Security;

use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

trait SecurityEventHandlerTrait
{
    abstract protected function getEntityManager(): EntityManagerInterface;
    abstract protected function getQueueService(): QueueService;
    abstract protected function getLogger(): LoggerInterface;

    protected function executeSecurityEvent(callable $operations, array $context): void
    {
        $this->getLogger()->info('Processing security event', $context);

        $em = $this->getEntityManager();
        $em->beginTransaction();

        try {
            $operations();
            $em->commit();
        } catch (\Throwable $e) {
            $em->rollback();
            $this->getLogger()->error('Security event failed', array_merge($context, [
                'error' => $e->getMessage(),
            ]));
            throw $e;
        }
    }

    protected function createSecurityAuditEntry(
        string $action,
        int $userId,
        array $metadata,
        ?int $actorId = null
    ): void {
        $entry = new AuditLog();
        $entry->setAction($action);
        $entry->setEntityType('user');
        $entry->setEntityId($userId);
        $entry->setUserId($actorId ?? $userId);
        $entry->setMetadata($metadata);
        $entry->setCreatedAt(new \DateTimeImmutable());

        $this->getEntityManager()->persist($entry);
    }

    protected function recordSecurityAnalytics(string $eventName, int $userId, array $payload): void
    {
        $event = new AnalyticsEvent();
        $event->setEventName($eventName);
        $event->setCustomerId($userId);
        $event->setPayload($payload);
        $event->setOccurredAt(new \DateTimeImmutable());

        $this->getEntityManager()->persist($event);
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

    protected function sendSecurityNotification(int $userId, string $templateCode, array $variables): void
    {
        $this->getQueueService()->publish('email.security', [
            'template' => $templateCode,
            'user_id' => $userId,
            'variables' => $variables,
            'priority' => 'high',
            'category' => 'security',
        ]);
    }
}
