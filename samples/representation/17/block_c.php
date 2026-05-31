<?php
declare(strict_types=1);

namespace App\Notification\ViewModel;

final class NotificationViewModel
{
    public string $id;
    public string $type;
    public string $typeLabel;
    public string $title;
    public string $body;
    public string $relativeTime;
    public string $createdAt;
    public string $icon;
    public string $iconClass;
    public string $bgClass;
    public string $actionUrl;
    public bool $isRead;
    public bool $isUrgent;
    public bool $showBadge;
    public string $badgeText;

    public static function fromDTO(NotificationDTO $dto): self
    {
        $vm = new self();
        $vm->id = $dto->id;
        $vm->type = $dto->type;
        $vm->typeLabel = self::getTypeLabel($dto->type);
        $vm->title = $dto->title;
        $vm->body = $dto->body;
        $vm->relativeTime = $dto->getRelativeTime();
        $vm->createdAt = $dto->createdAt;
        $vm->icon = $dto->icon;
        $vm->iconClass = self::getIconClass($dto->type);
        $vm->bgClass = self::getBgClass($dto->type, $dto->isRead);
        $vm->actionUrl = $dto->actionUrl;
        $vm->isRead = $dto->isRead;
        $vm->isUrgent = $dto->isUrgent;
        $vm->showBadge = !$dto->isRead;
        $vm->badgeText = $dto->isUrgent ? 'Urgent' : 'New';

        return $vm;
    }

    private static function getTypeLabel(string $type): string
    {
        return match ($type) {
            'order_confirmation' => 'Order Confirmation',
            'shipment_update' => 'Shipment Update',
            'delivery_complete' => 'Delivery Complete',
            'payment_received' => 'Payment Received',
            'account_update' => 'Account Update',
            'promotion' => 'Special Offer',
            default => ucfirst(str_replace('_', ' ', $type))
        };
    }

    private static function getIconClass(string $type): string
    {
        return match ($type) {
            'order_confirmation' => 'text-blue-500',
            'shipment_update' => 'text-orange-500',
            'delivery_complete' => 'text-green-500',
            'payment_received' => 'text-green-600',
            'account_update' => 'text-purple-500',
            'promotion' => 'text-red-500',
            default => 'text-gray-500'
        };
    }

    private static function getBgClass(string $type, bool $isRead): string
    {
        if ($isRead) {
            return 'bg-gray-50';
        }

        return match ($type) {
            'payment_received', 'delivery_complete' => 'bg-red-50',
            'promotion' => 'bg-orange-50',
            default => 'bg-blue-50'
        };
    }

    public function getCardClasses(): string
    {
        $classes = 'flex items-start p-4 border-b border-gray-200';

        if (!$this->isRead) {
            $classes .= ' bg-blue-50';
        }

        if ($this->isUrgent) {
            $classes .= ' border-l-4 border-red-500';
        }

        return $classes;
    }

    public function toViewData(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->typeLabel,
            'title' => $this->title,
            'body' => $this->body,
            'relative_time' => $this->relativeTime,
            'icon' => $this->icon,
            'icon_class' => $this->iconClass,
            'action_url' => $this->actionUrl,
            'is_read' => $this->isRead,
            'is_urgent' => $this->isUrgent,
            'show_badge' => $this->showBadge,
            'badge_text' => $this->badgeText,
            'card_classes' => $this->getCardClasses()
        ];
    }
}
