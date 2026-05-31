<?php
declare(strict_types=1);

namespace App\Domain\EventHandling;

use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

interface DomainEventHandlerInterface
{
    public function handle(): void;
    public function getEntityId(): int;
    public function getEventName(): string;
}

abstract class BaseEventHandler implements DomainEventHandlerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly QueueService $queueService,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function handle(): void
    {
        $this->logger->info('Processing ' . $this->getEventName() . ' event', $this->getLogContext());

        $this->entityManager->beginTransaction();
        try {
            $this->executeEventLogic();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Event processing failed', [
                'event' => $this->getEventName(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    abstract protected function executeEventLogic(): void;
    abstract protected function getLogContext(): array;

    protected function recordAnalytics(string $eventName, int $entityId, array $payload): void
    {
        $event = new AnalyticsEvent();
        $event->setEventName($eventName);
        $event->setCustomerId($entityId);
        $event->setPayload($payload);
        $event->setOccurredAt(new \DateTimeImmutable());
        $this->entityManager->persist($event);
    }

    protected function createAuditEntry(string $action, string $entityType, int $entityId, ?int $userId, array $metadata): void
    {
        $entry = new AuditLog();
        $entry->setAction($action);
        $entry->setEntityType($entityType);
        $entry->setEntityId($entityId);
        $entry->setUserId($userId ?? 0);
        $entry->setMetadata($metadata);
        $entry->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($entry);
    }
}
