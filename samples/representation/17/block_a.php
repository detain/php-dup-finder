<?php
declare(strict_types=1);

namespace App\Notification\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_notifications_user')]
#[ORM\Index(columns: ['is_read'], name: 'idx_notifications_read')]
class Notification
{
    public const TYPE_ORDER_CONFIRMATION = 'order_confirmation';
    public const TYPE_SHIPMENT_UPDATE = 'shipment_update';
    public const TYPE_DELIVERY_COMPLETE = 'delivery_complete';
    public const TYPE_PAYMENT_RECEIVED = 'payment_received';
    public const TYPE_ACCOUNT_UPDATE = 'account_update';
    public const TYPE_PROMOTION = 'promotion';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $userId;

    #[ORM\Column(type: 'string', length: 50)]
    private string $type;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $data = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isRead = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct(
        string $id,
        string $userId,
        string $type,
        string $title,
        string $body
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->type = $type;
        $this->title = $title;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function markAsRead(): void
    {
        $this->isRead = true;
        $this->readAt = new \DateTimeImmutable();
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'is_read' => $this->isRead,
            'created_at' => $this->createdAt->format('c'),
            'read_at' => $this->readAt?->format('c'),
            'expires_at' => $this->expiresAt?->format('c')
        ];
    }
}
