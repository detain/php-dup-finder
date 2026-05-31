<?php
declare(strict_types=1);

namespace App\Notification\Model;

use App\Notification\Entity\Notification;

final class NotificationModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data,
        public readonly bool $isRead,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $readAt = null,
        public readonly ?\DateTimeImmutable $expiresAt = null
    ) {}

    public static function fromEntity(Notification $notification): self
    {
        return new self(
            id: $notification->getId(),
            userId: $notification->getUserId(),
            type: $notification->getType(),
            title: $notification->getTitle(),
            body: $notification->getBody(),
            data: $notification->getData() ?? [],
            isRead: $notification->isRead(),
            createdAt: $notification->getCreatedAt(),
            readAt: $notification->getReadAt(),
            expiresAt: $notification->getExpiresAt()
        );
    }

    public function getRelativeTime(): string
    {
        $diff = (new \DateTimeImmutable())->getTimestamp() - $this->createdAt->getTimestamp();

        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . 'h ago';
        }
        return floor($diff / 86400) . 'd ago';
    }

    public function toDTO(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'is_read' => $this->isRead,
            'created_at' => $this->createdAt->format('c'),
            'relative_time' => $this->getRelativeTime()
        ];
    }

    public function toViewModel(): array
    {
        return [
            'id' => $this->id,
            'type_label' => ucfirst(str_replace('_', ' ', $this->type)),
            'title' => $this->title,
            'body' => $this->body,
            'relative_time' => $this->getRelativeTime(),
            'is_read' => $this->isRead,
            'show_badge' => !$this->isRead
        ];
    }
}
