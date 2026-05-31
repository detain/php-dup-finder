<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Entity;

use App\Domain\Analytics\ValueObject\EventId;

/**
 * Analytics event entity for tracking user interactions.
 * This entity is duplicated in:
 * - Database table: analytics_events
 * - Event tracking SDK schemas
 * - Data pipeline Avro/Parquet schemas
 * - Data warehouse dimension tables
 *
 * @ORM\Entity(repositoryClass=AnalyticsEventRepository::class)
 * @ORM\Table(name="analytics_events")
 * @ORM\Index(name="idx_event_type", columns={"event_type"})
 * @ORM\Index(name="idx_session_id", columns={"session_id"})
 * @ORM\Index(name="idx_user_id", columns={"user_id"})
 * @ORM\Index(name="idx_occurred_at", columns={"occurred_at"})
 * @ORM\Index(name="idx_entity", columns={"entity_type", "entity_id"})
 */
class AnalyticsEvent
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
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private ?string $eventCategory = null;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private ?string $eventLabel = null;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private ?string $entityType = null;

    /**
     * @ORM\Column(type="string", length=36, nullable=true)
     */
    private ?string $entityId = null;

    /**
     * @ORM\Column(type="string", length=36, nullable=true)
     */
    private ?string $userId = null;

    /**
     * @ORM\Column(type="string", length=36)
     */
    private string $sessionId;

    /**
     * @ORM\Column(type="string", length=36, nullable=true)
     */
    private ?string $pageViewId = null;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    private ?float $value = null;

    /**
     * @ORM\Column(type="json")
     */
    private array $properties = [];

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $userContext = null;

    /**
     * @ORM\Column(type="string", length=500, nullable=true)
     */
    private ?string $ipAddress = null;

    /**
     * @ORM\Column(type="string", length=500, nullable=true)
     */
    private ?string $userAgent = null;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private ?string $referrer = null;

    /**
     * @ORM\Column(type="string", length=500, nullable=true)
     */
    private ?string $pageUrl = null;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private ?string $pageTitle = null;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private ?string $utmSource = null;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private ?string $utmMedium = null;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private ?string $utmCampaign = null;

    /**
     * @ORM\Column(type="string", length=36, nullable=true)
     */
    private ?string $correlationId = null;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $occurredAt;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $eventType,
        string $sessionId,
        ?string $userId = null
    ) {
        $this->id = EventId::generate()->toString();
        $this->eventType = $eventType;
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->occurredAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEntity(?string $type, ?string $id): void
    {
        $this->entityType = $type;
        $this->entityId = $id;
    }

    public function setValue(float $value): void
    {
        $this->value = $value;
    }

    public function getValue(): ?float
    {
        return $this->value;
    }

    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function setUserContext(array $context): void
    {
        $this->userContext = $context;
    }

    public function getUserContext(): ?array
    {
        return $this->userContext;
    }

    public function setPageContext(string $pageUrl, string $pageTitle, ?string $referrer = null): void
    {
        $this->pageUrl = $pageUrl;
        $this->pageTitle = $pageTitle;
        $this->referrer = $referrer;
    }

    public function setUtmParams(?string $source, ?string $medium, ?string $campaign): void
    {
        $this->utmSource = $source;
        $this->utmMedium = $medium;
        $this->utmCampaign = $campaign;
    }

    public function setTrackingInfo(string $ipAddress, string $userAgent): void
    {
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->eventType,
            'event_category' => $this->eventCategory,
            'event_label' => $this->eventLabel,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'user_id' => $this->userId,
            'session_id' => $this->sessionId,
            'page_view_id' => $this->pageViewId,
            'value' => $this->value,
            'properties' => $this->properties,
            'user_context' => $this->userContext,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'referrer' => $this->referrer,
            'page_url' => $this->pageUrl,
            'page_title' => $this->pageTitle,
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
            'correlation_id' => $this->correlationId,
            'occurred_at' => $this->occurredAt->format(\DateTimeImmutable::ATOM),
            'created_at' => $this->createdAt->format(\DateTimeImmutable::ATOM),
        ];
    }
}
