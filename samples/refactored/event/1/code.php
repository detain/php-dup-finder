<?php
declare(strict_types=1);

namespace App\Domain\EventHandling;

use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\AnalyticsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

abstract class AbstractDomainEventHandler
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly QueueService $queueService,
        protected readonly AnalyticsService $analyticsService,
        protected readonly LoggerInterface $logger,
    ) {
    }

    protected function beginTransaction(): void
    {
        $this->entityManager->beginTransaction();
    }

    protected function commit(): void
    {
        $this->entityManager->commit();
    }

    protected function rollback(\Throwable $e): never
    {
        $this->entityManager->rollback();
        $this->logger->error('Transaction failed', ['error' => $e->getMessage()]);
        throw $e;
    }

    protected function recordAnalytics(string $eventName, int $entityId, array $payload): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName($eventName);
        $analyticsEvent->setCustomerId($entityId);
        $analyticsEvent->setPayload($payload);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);
        $this->analyticsService->enqueueBatchFlush();

        $this->logger->debug('Recorded analytics event', ['event' => $eventName]);
    }

    protected function createAuditEntry(
        string $action,
        string $entityType,
        int $entityId,
        ?int $userId,
        array $metadata
    ): void {
        $auditEntry = new AuditLog();
        $auditEntry->setAction($action);
        $auditEntry->setEntityType($entityType);
        $auditEntry->setEntityId($entityId);
        $auditEntry->setUserId($userId);
        $auditEntry->setMetadata($metadata);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', ['action' => $action]);
    }

    abstract protected function getEntityId(): int;
    abstract protected function getEventName(): string;
    abstract protected function getLogContext(): array;
    abstract protected function executeCustomLogic(): void;

    public function handle(): void
    {
        $this->logger->info('Processing ' . $this->getEventName() . ' event', $this->getLogContext());

        $this->beginTransaction();
        try {
            $this->executeCustomLogic();
            $this->commit();

            $this->logger->info($this->getEventName() . ' event processed successfully', [
                'entity_id' => $this->getEntityId(),
            ]);
        } catch (\Throwable $e) {
            $this->rollback($e);
        }
    }
}
