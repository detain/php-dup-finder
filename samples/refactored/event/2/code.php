<?php
declare(strict_types=1);

namespace App\Domain\EventHandling;

use App\Entity\AuditLog;
use App\Service\QueueService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

trait EventHandlerTransactionalTrait
{
    abstract protected function getEntityManager(): EntityManagerInterface;
    abstract protected function getLogger(): LoggerInterface;

    protected function executeInTransaction(callable $operations): void
    {
        $em = $this->getEntityManager();
        $em->beginTransaction();

        try {
            $operations();
            $em->commit();
        } catch (\Throwable $e) {
            $em->rollback();
            $this->getLogger()->error('Transaction failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}

trait EventHandlerAuditTrait
{
    abstract protected function getEntityManager(): EntityManagerInterface;

    protected function createAuditEntry(
        string $action,
        string $entityType,
        int $entityId,
        array $metadata,
        ?int $userId = null
    ): void {
        $auditEntry = new AuditLog();
        $auditEntry->setAction($action);
        $auditEntry->setEntityType($entityType);
        $auditEntry->setEntityId($entityId);
        $auditEntry->setUserId($userId ?? 0);
        $auditEntry->setMetadata($metadata);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->getEntityManager()->persist($auditEntry);
    }
}

trait EventHandlerAnalyticsTrait
{
    use EventHandlerAuditTrait;

    abstract protected function getEntityManager(): EntityManagerInterface;
    abstract protected function getQueueService(): QueueService;

    protected function recordAnalyticsEvent(
        string $eventName,
        int $entityId,
        array $payload
    ): void {
        $analyticsEvent = new \App\Entity\AnalyticsEvent();
        $analyticsEvent->setEventName($eventName);
        $analyticsEvent->setCustomerId($entityId);
        $analyticsEvent->setPayload($payload);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->getEntityManager()->persist($analyticsEvent);

        $this->getQueueService()->publish('analytics.events', [
            'event' => $eventName,
            'entity_id' => $entityId,
            'payload' => $payload,
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);
    }
}
