<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Entity\User;
use App\Entity\SmsNotification;
use App\Repository\SmsNotificationRepository;
use App\Service\Sms\SmsGateway;
use Psr\Log\LoggerInterface;

final class SmsNotificationService
{
    public function __construct(
        private readonly SmsGateway $smsGateway,
        private readonly SmsNotificationRepository $notificationRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendNotification(User $user, string $message): bool
    {
        $notification = new SmsNotification();
        $notification->setUserId($user->getId());
        $notification->setChannel('sms');
        $notification->setMessage($message);
        $notification->setStatus('pending');
        $notification->setCreatedAt(new \DateTimeImmutable());

        try {
            $phoneNumber = $user->getPhoneNumber();

            if ($phoneNumber === null || !$this->isValidPhoneNumber($phoneNumber)) {
                $this->logger->warning('Invalid phone number for user', [
                    'user_id' => $user->getId(),
                    'phone' => $phoneNumber,
                ]);
                $notification->setStatus('failed');
                $notification->setFailureReason('Invalid phone number');
                $this->notificationRepository->save($notification);
                return false;
            }

            $result = $this->smsGateway->send($phoneNumber, $message);

            if ($result->isSuccess()) {
                $notification->setStatus('sent');
                $notification->setSentAt(new \DateTimeImmutable());
                $notification->setExternalId($result->getMessageId());
                $this->logger->info('SMS notification sent', [
                    'user_id' => $user->getId(),
                    'notification_id' => $notification->getId(),
                    'message_id' => $result->getMessageId(),
                ]);
            } else {
                $notification->setStatus('failed');
                $notification->setFailureReason($result->getErrorMessage());
                $this->logger->error('SMS notification failed', [
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
            $this->logger->error('SMS notification exception', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    public function sendBulkNotifications(array $userIds, string $message): array
    {
        $results = [];

        foreach ($userIds as $userId) {
            $user = $this->userRepository->find($userId);
            if ($user === null) {
                $results[$userId] = false;
                continue;
            }
            $results[$userId] = $this->sendNotification($user, $message);
        }

        $successCount = count(array_filter($results));
        $this->logger->info('Bulk SMS notification completed', [
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

        return $this->sendNotification($user, $notification->getMessage());
    }

    private function isValidPhoneNumber(string $phoneNumber): bool
    {
        $cleaned = preg_replace('/[^0-9+]/', '', $phoneNumber);
        return strlen($cleaned) >= 10 && strlen($cleaned) <= 15;
    }
}
