<?php
declare(strict_types=1);

namespace App\Support;

use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

abstract class AbstractSupportEventHandler
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly QueueService $queueService,
        protected readonly LoggerInterface $logger,
    ) {
    }

    protected function executeWithTransaction(callable $operations, array $context): void
    {
        $this->logger->info('Processing support event', $context);

        $this->entityManager->beginTransaction();
        try {
            $operations();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Support event failed', array_merge($context, [
                'error' => $e->getMessage(),
            ]));
            throw $e;
        }
    }

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
