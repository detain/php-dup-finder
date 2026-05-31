<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\User;
use App\Entity\Notification;
use Psr\Log\LoggerInterface;

final class UnifiedNotificationService
{
    /** @var array<string, NotificationChannelInterface> */
    private array $channels = [];

    /** @var array<string, NotificationRepositoryInterface> */
    private array $repositories = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->registerChannels();
    }

    private function registerChannels(): void
    {
        $this->channels['email'] = new EmailChannel();
        $this->channels['sms'] = new SmsChannel();
        $this->channels['push'] = new PushChannel();

        $this->repositories['email'] = new EmailNotificationRepository();
        $this->repositories['sms'] = new SmsNotificationRepository();
        $this->repositories['push'] = new PushNotificationRepository();
    }

    public function send(string $channel, User $user, NotificationPayload $payload): bool
    {
        $channelHandler = $this->channels[$channel] ?? null;
        $repository = $this->repositories[$channel] ?? null;

        if ($channelHandler === null || $repository === null) {
            $this->logger->warning('Unknown notification channel', ['channel' => $channel]);
            return false;
        }

        $notification = $this->createNotification($channel, $user, $payload);

        $validationResult = $channelHandler->validateRecipient($user);
        if (!$validationResult->isValid()) {
            $notification->markFailed($validationResult->getError());
            $repository->save($notification);
            $this->logger->warning("Invalid recipient for {$channel}", [
                'user_id' => $user->getId(),
                'error' => $validationResult->getError(),
            ]);
            return false;
        }

        try {
            $sendResult = $channelHandler->send($user, $payload);

            if ($sendResult->isSuccess()) {
                $notification->markSent($sendResult->getMessageId());
                $this->logger->info("{$channel} notification sent", [
                    'user_id' => $user->getId(),
                    'notification_id' => $notification->getId(),
                ]);
            } else {
                $notification->markFailed($sendResult->getErrorMessage());
                $this->logger->error("{$channel} notification failed", [
                    'user_id' => $user->getId(),
                    'error' => $sendResult->getErrorMessage(),
                ]);
            }

            $repository->save($notification);
            return $notification->isSent();

        } catch (\Throwable $e) {
            $notification->markFailed($e->getMessage());
            $repository->save($notification);
            $this->logger->error("{$channel} notification exception", [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function sendBulk(string $channel, array $userIds, NotificationPayload $payload): array
    {
        $results = [];

        foreach ($userIds as $userId) {
            $user = $this->userRepository->find($userId);
            $results[$userId] = $user !== null && $this->send($channel, $user, $payload);
        }

        $successCount = count(array_filter($results));
        $this->logger->info("Bulk {$channel} notification completed", [
            'total' => count($userIds),
            'successful' => $successCount,
        ]);

        return $results;
    }

    public function retry(string $channel, int $notificationId): bool
    {
        $repository = $this->repositories[$channel] ?? null;
        if ($repository === null) {
            return false;
        }

        $notification = $repository->find($notificationId);
        if ($notification === null || !$notification->isFailed()) {
            return false;
        }

        $user = $this->userRepository->find($notification->getUserId());
        if ($user === null) {
            return false;
        }

        $notification->markPending();
        $notification->incrementRetryCount();

        $payload = NotificationPayload::fromArray($notification->toArray());

        return $this->send($channel, $user, $payload);
    }

    private function createNotification(string $channel, User $user, NotificationPayload $payload): Notification
    {
        $notification = new Notification();
        $notification->setChannel($channel);
        $notification->setUserId($user->getId());
        $notification->setSubject($payload->subject ?? $payload->title ?? '');
        $notification->setBody($payload->body ?? $payload->message ?? '');
        $notification->setStatus('pending');
        $notification->setCreatedAt(new \DateTimeImmutable());
        return $notification;
    }
}

interface NotificationChannelInterface
{
    public function validateRecipient(User $user): ValidationResult;
    public function send(User $user, NotificationPayload $payload): SendResult;
}

interface NotificationRepositoryInterface
{
    public function save(Notification $notification): void;
    public function find(int $id): ?Notification;
}

final class EmailChannel implements NotificationChannelInterface
{
    public function validateRecipient(User $user): ValidationResult
    {
        $email = $user->getEmail();
        if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ValidationResult::invalid('Invalid email address');
        }
        return ValidationResult::valid();
    }

    public function send(User $user, NotificationPayload $payload): SendResult
    {
        return SendResult::success('msg_' . bin2hex(random_bytes(8)));
    }
}

final class SmsChannel implements NotificationChannelInterface
{
    public function validateRecipient(User $user): ValidationResult
    {
        $phone = $user->getPhoneNumber();
        if ($phone === null || !preg_match('/^[0-9+]{10,15}$/', preg_replace('/[^0-9+]/', '', $phone))) {
            return ValidationResult::invalid('Invalid phone number');
        }
        return ValidationResult::valid();
    }

    public function send(User $user, NotificationPayload $payload): SendResult
    {
        return SendResult::success('sms_' . bin2hex(random_bytes(8)));
    }
}

final class PushChannel implements NotificationChannelInterface
{
    public function validateRecipient(User $user): ValidationResult
    {
        $token = $user->getDeviceToken();
        if ($token === null || strlen($token) < 32) {
            return ValidationResult::invalid('Invalid device token');
        }
        return ValidationResult::valid();
    }

    public function send(User $user, NotificationPayload $payload): SendResult
    {
        return SendResult::success('push_' . bin2hex(random_bytes(8)));
    }
}
