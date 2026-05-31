<?php

declare(strict_types=1);

namespace App\Domain\Audit\Entity;

use App\Domain\Audit\ValueObject\AuditEventId;

/**
 * Doctrine entity for audit log entries.
 * This entity definition is duplicated in:
 * - Database table: audit_logs
 * - Event store: event_store table
 * - Log aggregation service schema
 * - Compliance reporting DTOs
 *
 * @ORM\Entity(repositoryClass=AuditLogRepository::class)
 * @ORM\Table(name="audit_logs")
 * @ORM\Index(name="idx_event_type", columns={"event_type"})
 * @ORM\Index(name="idx_actor_id", columns={"actor_id"})
 * @ORM\Index(name="idx_entity_type", columns={"entity_type"})
 * @ORM\Index(name="idx_occurred_at", columns={"occurred_at"})
 * @ORM\Index(name="idx_entity_actor", columns={"entity_type", "entity_id", "actor_id"})
 */
class AuditLog
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=36)
     */
    private string $id;

    /**
     * @ORM\Column(type="string", length=100)
     */
    private string $eventType;

    /**
     * @ORM\Column(type="string", length=100)
     */
    private string $entityType;

    /**
     * @ORM\Column(type="string", length=36)
     */
    private string $entityId;

    /**
     * @ORM\Column(type="string", length=36, nullable=true)
     */
    private ?string $actorId = null;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private ?string $actorType = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $actorIpAddress = null;

    /**
     * @ORM\Column(type="json")
     */
    private array $eventData = [];

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $metadata = null;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $occurredAt;

    /**
     * @ORM\Column(type="string", length=36, nullable=true)
     */
    private ?string $correlationId = null;

    /**
     * @ORM\Column(type="string", length=36, nullable=true)
     */
    private ?string $causationId = null;

    public function __construct(
        string $eventType,
        string $entityType,
        string $entityId,
        ?string $actorId = null,
        ?string $actorType = null
    ) {
        $this->id = AuditEventId::generate()->toString();
        $this->eventType = $eventType;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->actorId = $actorId;
        $this->actorType = $actorType;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function setActorIpAddress(?string $ipAddress): void
    {
        $this->actorIpAddress = $ipAddress;
    }

    public function setEventData(array $data): void
    {
        $this->eventData = $data;
    }

    public function getEventData(): array
    {
        return $this->eventData;
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setCorrelationId(?string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function setCausationId(?string $causationId): void
    {
        $this->causationId = $causationId;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->eventType,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'actor_id' => $this->actorId,
            'actor_type' => $this->actorType,
            'actor_ip_address' => $this->actorIpAddress,
            'event_data' => $this->eventData,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurredAt->format(\DateTimeImmutable::ATOM),
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
        ];
    }
}
