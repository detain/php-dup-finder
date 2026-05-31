<?php
declare(strict_types=1);

namespace App\Core\Notification\Authorization;

use App\Domain\Entity\User;
use App\Domain\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

enum NotificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Push = 'push';
    case InApp = 'inapp';
}

interface NotificationPermissionStrategy
{
    public function getChannel(): NotificationChannel;
    public function requiresRecipientVerification(): bool;
    public function validateRecipient(User $recipient): bool;
}

abstract class BaseNotificationPermissionService
{
    public function __construct(
        protected readonly UserRepositoryInterface $userRepository,
        protected readonly LoggerInterface $logger,
    ) {}

    public function canSend(User $user, string $recipientId, NotificationPermissionStrategy $strategy): bool
    {
        if ($user === null) {
            $this->logFailure('null sender', $strategy);
            return false;
        }

        if (!$user->isActive()) {
            $this->logFailure('sender inactive', $strategy, ['sender_id' => $user->getId()->toString()]);
            return false;
        }

        $permission = $this->getPermissionForChannel($strategy->getChannel());
        if (!$user->hasPermission('notification', $permission, 'send')) {
            $this->logFailure('no send permission', $strategy, ['sender_id' => $user->getId()->toString()]);
            return false;
        }

        $recipient = $this->userRepository->findById($recipientId);
        if ($recipient === null) {
            $this->logFailure('recipient not found', $strategy, ['recipient_id' => $recipientId]);
            return false;
        }

        if (!$this->isChannelEnabledForRecipient($recipient, $strategy->getChannel())) {
            $this->logFailure('channel disabled for recipient', $strategy, ['recipient_id' => $recipientId]);
            return false;
        }

        if ($strategy->requiresRecipientVerification() && !$strategy->validateRecipient($recipient)) {
            $this->logFailure('recipient verification failed', $strategy, ['recipient_id' => $recipientId]);
            return false;
        }

        $this->logSuccess($strategy, ['sender_id' => $user->getId()->toString(), 'recipient_id' => $recipientId]);

        return true;
    }

    private function getPermissionForChannel(NotificationChannel $channel): string
    {
        return match ($channel) {
            NotificationChannel::Email => 'email',
            NotificationChannel::Sms => 'sms',
            NotificationChannel::Push => 'push',
            NotificationChannel::InApp => 'inapp',
        };
    }

    private function isChannelEnabledForRecipient(User $recipient, NotificationChannel $channel): bool
    {
        return match ($channel) {
            NotificationChannel::Email => $recipient->getNotificationPreferences()->isEmailEnabled(),
            NotificationChannel::Sms => $recipient->getNotificationPreferences()->isSmsEnabled(),
            NotificationChannel::Push => $recipient->getNotificationPreferences()->isPushEnabled(),
            NotificationChannel::InApp => $recipient->getNotificationPreferences()->isInAppEnabled(),
        };
    }

    private function logFailure(string $reason, NotificationPermissionStrategy $strategy, array $context = []): void
    {
        $this->logger->warning("Notification permission denied: {$reason}", array_merge(
            ['channel' => $strategy->getChannel()->value],
            $context
        ));
    }

    private function logSuccess(NotificationPermissionStrategy $strategy, array $context = []): void
    {
        $this->logger->debug('Notification permission granted', array_merge(
            ['channel' => $strategy->getChannel()->value],
            $context
        ));
    }
}

final class NotificationPermissionService extends BaseNotificationPermissionService {}
