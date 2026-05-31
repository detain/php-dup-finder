<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Entity\User;
use App\Entity\PushNotification;
use App\Repository\PushNotificationRepository;
use App\Service\Push\PushBroker;
use Psr\Log\LoggerInterface;

final class PushNotificationService
{
    public function __construct(
        private readonly PushBroker $pushBroker,
        private readonly PushNotificationRepository $notificationRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendNotification(User $user, string $title, string $body, array $data = []): bool
    {
        $notification = new PushNotification();
        $notification->setUserId($user->getId());
        $notification->setChannel('push');
        $notification->setTitle($title);
        $notification->setBody($body);
        $notification->setStatus('pending');
        $notification->setCreatedAt(new \DateTimeImmutable());

        try {
            $deviceToken = $user->getDeviceToken();

            if ($deviceToken === null || strlen($deviceToken) < 32) {
                $this->logger->warning('Invalid device token for user', [
                    'user_id' => $user->getId(),
                    'token_length' => $deviceToken !== null ? strlen($deviceToken) : 0,
                ]);
                $notification->setStatus('failed');
                $notification->setFailureReason('Invalid device token');
                $this->notificationRepository->save($notification);
                return false;
            }

            $payload = [
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ];

            $result = $this->pushBroker->send($deviceToken, $payload);

            if ($result->isSuccess()) {
                $notification->setStatus('sent');
                $notification->setSentAt(new \DateTimeImmutable());
                $notification->setExternalId($result->getMessageId());
                $this->logger->info('Push notification sent', [
                    'user_id' => $user->getId(),
                    'notification_id' => $notification->getId(),
                    'message_id' => $result->getMessageId(),
                ]);
            } else {
                $notification->setStatus('failed');
                $notification->setFailureReason($result->getErrorMessage());
                $this->logger->error('Push notification failed', [
                    'user_id' => $user->getId(),
                    'error' => $result->getErrorMessage(),
                ]);
            }

            $this->notificationRepository->save($notification);
            return $notification->getStatus() === 'sent';

        } catch (\Throwable $e) {
            $notification->setStatus('failed');
            $notification->setFailureReason($e->getMessage());
            $this->notificationRepository->save($notification);
            $this->logger->error('Push notification exception', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    public function sendBulkNotifications(array $userIds, string $title, string $body, array $data = []): array
    {
        $results = [];

        foreach ($userIds as $userId) {
            $user = $this->userRepository->find($userId);
            if ($user === null) {
                $results[$userId] = false;
                continue;
            }
            $results[$userId] = $this->sendNotification($user, $title, $body, $data);
        }

        $successCount = count(array_filter($results));
        $this->logger->info('Bulk push notification completed', [
            'total' => count($userIds),
            'successful' => $successCount,
            'failed' => count($userIds) - $successCount,
        ]);

        return $results;
    }

    public function retryFailedNotification(int $notificationId): bool
    {
        $notification = $this->notificationRepository->find($notificationId);

        if ($notification === null || $notification->getStatus() !== 'failed') {
            return false;
        }

        $user = $this->userRepository->find($notification->getUserId());
        if ($user === null) {
            return false;
        }

        $notification->setStatus('pending');
        $notification->setRetryCount($notification->getRetryCount() + 1);
        $notification->setLastRetryAt(new \DateTimeImmutable());

        return $this->sendNotification($user, $notification->getTitle(), $notification->getBody(), []);
    }

    private function buildPayload(string $title, string $body, array $data): array
    {
        return [
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $data,
        ];
    }
}
