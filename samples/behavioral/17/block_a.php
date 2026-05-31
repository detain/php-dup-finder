<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Entity\User;
use App\Entity\Notification;
use App\Repository\NotificationRepository;
use App\Service\Email\EmailSender;
use Psr\Log\LoggerInterface;

final class EmailNotificationService
{
    public function __construct(
        private readonly EmailSender $emailSender,
        private readonly NotificationRepository $notificationRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendNotification(User $user, string $subject, string $template, array $data = []): bool
    {
        $notification = new Notification();
        $notification->setUserId($user->getId());
        $notification->setChannel('email');
        $notification->setSubject($subject);
        $notification->setStatus('pending');
        $notification->setCreatedAt(new \DateTimeImmutable());

        try {
            $emailAddress = $user->getEmail();

            if ($emailAddress === null || !filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
                $this->logger->warning('Invalid email address for user', [
                    'user_id' => $user->getId(),
                    'email' => $emailAddress,
                ]);
                $notification->setStatus('failed');
                $notification->setFailureReason('Invalid email address');
                $this->notificationRepository->save($notification);
                return false;
            }

            $renderedSubject = $this->renderTemplate($subject, $data);
            $renderedBody = $this->renderTemplate($template, $data);

            $result = $this->emailSender->send($emailAddress, $renderedSubject, $renderedBody);

            if ($result->isSuccess()) {
                $notification->setStatus('sent');
                $notification->setSentAt(new \DateTimeImmutable());
                $notification->setExternalId($result->getMessageId());
                $this->logger->info('Email notification sent', [
                    'user_id' => $user->getId(),
                    'notification_id' => $notification->getId(),
                    'message_id' => $result->getMessageId(),
                ]);
            } else {
                $notification->setStatus('failed');
                $notification->setFailureReason($result->getErrorMessage());
                $this->logger->error('Email notification failed', [
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
            $this->logger->error('Email notification exception', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    public function sendBulkNotifications(array $userIds, string $subject, string $template, array $data = []): array
    {
        $results = [];

        foreach ($userIds as $userId) {
            $user = $this->userRepository->find($userId);
            if ($user === null) {
                $results[$userId] = false;
                continue;
            }
            $results[$userId] = $this->sendNotification($user, $subject, $template, $data);
        }

        $successCount = count(array_filter($results));
        $this->logger->info('Bulk email notification completed', [
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

        return $this->sendNotification($user, $notification->getSubject(), '', []);

        return false;
    }

    private function renderTemplate(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }
        return $template;
    }
}
