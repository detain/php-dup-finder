<?php
declare(strict_types=1);

namespace Audit\Shared;

interface AuditLogStrategy
{
    public function prepareMetadata(mixed $entity, mixed $context): array;
    public function getEventType(): string;
}

abstract class BaseAuditLogger
{
    protected LoggerInterface $logger;
    protected AuditEventSerializer $serializer;
    protected AuditEventRepository $repository;

    private const BUFFER_SIZE = 100;
    private const FLUSH_INTERVAL_SECONDS = 60;

    public function log(AuditEntry $entry): void
    {
        $this->logger->info('Audit event', [
            'event_type' => $entry->eventType,
            'entity_type' => $entry->entityType,
        ]);

        $this->bufferEvent($this->serializer->serialize($entry));
    }

    public function logEvent(
        string $eventType,
        mixed $entity,
        mixed $context,
        array $additionalMetadata = []
    ): void {
        $entry = new AuditEntry(
            eventType: $eventType,
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: $this->getEntityType(),
            entityId: $this->getEntityId($entity),
            metadata: array_merge($this->prepareMetadata($entity, $context), $additionalMetadata),
        );

        $this->log($entry);
    }

    private function bufferEvent(string $serializedEntry): void
    {
        static $buffer = [];
        static $lastFlush = 0;

        $buffer[] = $serializedEntry;

        if (count($buffer) >= self::BUFFER_SIZE || (time() - $lastFlush) >= self::FLUSH_INTERVAL_SECONDS) {
            $this->flushBuffer($buffer);
            $buffer = [];
            $lastFlush = time();
        }
    }

    private function flushBuffer(array $buffer): void
    {
        foreach ($buffer as $serializedEntry) {
            try {
                $this->repository->persist($this->serializer->deserialize($serializedEntry));
            } catch (\Throwable $e) {
                $this->logger->error('Audit persist failed', ['error' => $e->getMessage()]);
            }
        }
    }

    abstract protected function getEntityType(): string;
    abstract protected function getEntityId(mixed $entity): string;
    abstract protected function prepareMetadata(mixed $entity, mixed $context): array;
}

final class UserAuditLogger extends BaseAuditLogger
{
    protected function getEntityType(): string
    {
        return 'user';
    }

    protected function getEntityId(mixed $entity): string
    {
        return $entity->getId();
    }

    protected function prepareMetadata(mixed $entity, mixed $context): array
    {
        return [
            'ip_address' => $context->getIpAddress(),
            'user_agent' => $context->getUserAgent(),
        ];
    }
}
