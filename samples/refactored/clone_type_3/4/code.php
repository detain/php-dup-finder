<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Entity\User;
use Psr\Log\LoggerInterface;

interface NotificationChannelInterface
{
    public function send(User $user, Notification $notification): bool;
    public function supports(User $user): bool;
}

abstract class AbstractNotificationService
{
    /** @var NotificationChannelInterface[] */
    private array $channels = [];

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    public function registerChannel(NotificationChannelInterface $channel): void
    {
        $this->channels[] = $channel;
    }

    public function sendWelcome(int $userId): array
    {
        $user = $this->getUser($userId);
        $notification = $this->buildWelcomeNotification($user);

        return $this->sendThroughChannels($user, $notification);
    }

    public function sendPasswordReset(int $userId): array
    {
        $user = $this->getUser($userId);
        $notification = $this->buildPasswordResetNotification($user);

        return $this->sendThroughChannels($user, $notification);
    }

    protected function sendThroughChannels(User $user, Notification $notification): array
    {
        $results = ['success' => 0, 'failure' => 0, 'skipped' => 0];

        foreach ($this->channels as $channel) {
            if (!$channel->supports($user)) {
                $results['skipped']++;
                continue;
            }

            if ($channel->send($user, $notification)) {
                $results['success']++;
            } else {
                $results['failure']++;
            }
        }

        return $results;
    }

    abstract protected function getUser(int $userId): ?User;
    abstract protected function buildWelcomeNotification(User $user): Notification;
    abstract protected function buildPasswordResetNotification(User $user): Notification;
}

final class Notification
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?string $actionLink = null,
    ) {}
}

final class EmailChannel implements NotificationChannelInterface
{
    public function send(User $user, Notification $notification): bool
    {
        // Email sending logic
        return true;
    }

    public function supports(User $user): bool
    {
        return $user->getEmail() !== null;
    }
}

final class SmsChannel implements NotificationChannelInterface
{
    private int $maxRetries = 3;

    public function send(User $user, Notification $notification): bool
    {
        if (!$this->supports($user)) {
            return false;
        }

        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            if ($this->doSend($user, $notification)) {
                return true;
            }
            usleep(500000);
        }

        return false;
    }

    public function supports(User $user): bool
    {
        return $user->getPhone() !== null;
    }

    private function doSend(User $user, Notification $notification): bool
    {
        // SMS sending logic
        return true;
    }
}
