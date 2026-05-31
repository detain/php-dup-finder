<?php
declare(strict_types=1);

namespace App\Notification\DTO;

final class NotificationDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data,
        public readonly bool $isRead,
        public readonly string $createdAt,
        public readonly ?string $readAt,
        public readonly ?string $expiresAt,
        public readonly string $icon,
        public readonly string $actionUrl,
        public readonly bool $isUrgent
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
            createdAt: $notification->getCreatedAt()->format('c'),
            readAt: $notification->getReadAt()?->format('c'),
            expiresAt: $notification->getExpiresAt()?->format('c'),
            icon: self::getIconForType($notification->getType()),
            actionUrl: self::getActionUrlForType($notification->getType(), $notification->getData()),
            isUrgent: self::isUrgentType($notification->getType())
        );
    }

    private static function getIconForType(string $type): string
    {
        return match ($type) {
            'order_confirmation' => 'shopping-bag',
            'shipment_update' => 'truck',
            'delivery_complete' => 'check-circle',
            'payment_received' => 'credit-card',
            'account_update' => 'user',
            'promotion' => 'tag',
            default => 'bell'
        };
    }

    private static function getActionUrlForType(string $type, ?array $data): string
    {
        if ($data === null) {
            return '/notifications';
        }

        return match ($type) {
            'order_confirmation', 'shipment_update', 'delivery_complete' =>
                '/account/orders/' . ($data['order_id'] ?? ''),
            'payment_received' => '/account/payments/' . ($data['payment_id'] ?? ''),
            'account_update' => '/account/settings',
            'promotion' => '/promotions/' . ($data['promotion_id'] ?? ''),
            default => '/notifications'
        };
    }

    private static function isUrgentType(string $type): bool
    {
        return in_array($type, ['payment_received', 'delivery_complete'], true);
    }

    public function getRelativeTime(): string
    {
        $created = new \DateTimeImmutable($this->createdAt);
        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $created->getTimestamp();

        if ($diff < 60) {
            return 'Just now';
        }

        if ($diff < 3600) {
            $minutes = (int) floor($diff / 60);
            return "{$minutes}m ago";
        }

        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            return "{$hours}h ago";
        }

        $days = (int) floor($diff / 86400);
        return "{$days}d ago";
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'is_read' => $this->isRead,
            'created_at' => $this->createdAt,
            'relative_time' => $this->getRelativeTime(),
            'icon' => $this->icon,
            'action_url' => $this->actionUrl,
            'is_urgent' => $this->isUrgent
        ];
    }
}
