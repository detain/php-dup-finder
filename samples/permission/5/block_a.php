<?php
declare(strict_types=1);

namespace App\Notification\Authorization;

use App\Domain\Entity\User;
use App\Domain\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class NotificationPermissionService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private LoggerInterface $logger,
    ) {}

    public function canSendEmailNotification(User $user, string $recipientId): bool
    {
        if ($user === null) {
            $this->logger->warning('Email notification permission denied: null sender');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Email notification permission denied: sender inactive', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        if (!$user->hasPermission('notification', 'email', 'send')) {
            $this->logger->info('Email notification permission denied: no send permission', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        $recipient = $this->userRepository->findById($recipientId);
        if ($recipient === null) {
            $this->logger->info('Email notification permission denied: recipient not found', [
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        if (!$recipient->getNotificationPreferences()->isEmailEnabled()) {
            $this->logger->info('Email notification permission denied: recipient disabled email', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        if (!$recipient->hasVerifiedEmail()) {
            $this->logger->info('Email notification permission denied: recipient unverified email', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        $this->logger->debug('Email notification permission granted', [
            'sender_id' => $user->getId()->toString(),
            'recipient_id' => $recipientId,
        ]);

        return true;
    }

    public function canSendSmsNotification(User $user, string $recipientId): bool
    {
        if ($user === null) {
            $this->logger->warning('SMS notification permission denied: null sender');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('SMS notification permission denied: sender inactive', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        if (!$user->hasPermission('notification', 'sms', 'send')) {
            $this->logger->info('SMS notification permission denied: no send permission', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        $recipient = $this->userRepository->findById($recipientId);
        if ($recipient === null) {
            $this->logger->info('SMS notification permission denied: recipient not found', [
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        if (!$recipient->getNotificationPreferences()->isSmsEnabled()) {
            $this->logger->info('SMS notification permission denied: recipient disabled SMS', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        if (!$recipient->hasVerifiedPhone()) {
            $this->logger->info('SMS notification permission denied: recipient unverified phone', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        $this->logger->debug('SMS notification permission granted', [
            'sender_id' => $user->getId()->toString(),
            'recipient_id' => $recipientId,
        ]);

        return true;
    }

    public function canSendPushNotification(User $user, string $recipientId): bool
    {
        if ($user === null) {
            $this->logger->warning('Push notification permission denied: null sender');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Push notification permission denied: sender inactive', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        if (!$user->hasPermission('notification', 'push', 'send')) {
            $this->logger->info('Push notification permission denied: no send permission', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        $recipient = $this->userRepository->findById($recipientId);
        if ($recipient === null) {
            $this->logger->info('Push notification permission denied: recipient not found', [
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        if (!$recipient->getNotificationPreferences()->isPushEnabled()) {
            $this->logger->info('Push notification permission denied: recipient disabled push', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        if (!$recipient->hasPushToken()) {
            $this->logger->info('Push notification permission denied: recipient no push token', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        $this->logger->debug('Push notification permission granted', [
            'sender_id' => $user->getId()->toString(),
            'recipient_id' => $recipientId,
        ]);

        return true;
    }

    public function canSendInAppNotification(User $user, string $recipientId): bool
    {
        if ($user === null) {
            $this->logger->warning('In-app notification permission denied: null sender');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('In-app notification permission denied: sender inactive', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        if (!$user->hasPermission('notification', 'inapp', 'send')) {
            $this->logger->info('In-app notification permission denied: no send permission', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        $recipient = $this->userRepository->findById($recipientId);
        if ($recipient === null) {
            $this->logger->info('In-app notification permission denied: recipient not found', [
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        if (!$recipient->getNotificationPreferences()->isInAppEnabled()) {
            $this->logger->info('In-app notification permission denied: recipient disabled in-app', [
                'sender_id' => $user->getId()->toString(),
                'recipient_id' => $recipientId,
            ]);
            return false;
        }

        $this->logger->debug('In-app notification permission granted', [
            'sender_id' => $user->getId()->toString(),
            'recipient_id' => $recipientId,
        ]);

        return true;
    }
}
