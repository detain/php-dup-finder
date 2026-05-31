<?php
declare(strict_types=1);

namespace App\Notification\Authorization;

use App\Domain\Entity\User;
use App\Domain\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class BulkNotificationPermissionService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private LoggerInterface $logger,
    ) {}

    public function canSendBulkEmail(User $user, array $recipientIds): bool
    {
        if ($user === null) {
            $this->logger->warning('Bulk email permission denied: null sender');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Bulk email permission denied: sender inactive', [
                'sender_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('notification', 'email', 'send')) {
            $this->logger->info('Bulk email permission denied: no send permission', [
                'sender_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('notification', 'email', 'bulk_send')) {
            $this->logger->info('Bulk email permission denied: no bulk send permission', [
                'sender_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $validRecipients = 0;
        foreach ($recipientIds as $recipientId) {
            $recipient = $this->userRepository->findById($recipientId);
            if ($recipient !== null &&
                $recipient->getNotificationPreferences()->isEmailEnabled() &&
                $recipient->hasVerifiedEmail()) {
                $validRecipients++;
            }
        }

        if ($validRecipients === 0) {
            $this->logger->info('Bulk email permission denied: no valid recipients', [
                'sender_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Bulk email permission granted', [
            'sender_id' => $user->getId()->toString(),
            'valid_recipients' => $validRecipients,
        ]);

        return true;
    }

    public function canSendBulkSms(User $user, array $recipientIds): bool
    {
        if ($user === null) {
            $this->logger->warning('Bulk SMS permission denied: null sender');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Bulk SMS permission denied: sender inactive', [
                'sender_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('notification', 'sms', 'send')) {
            $this->logger->info('Bulk SMS permission denied: no send permission', [
                'sender_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('notification', 'sms', 'bulk_send')) {
            $this->logger->info('Bulk SMS permission denied: no bulk send permission', [
                'sender_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $validRecipients = 0;
        foreach ($recipientIds as $recipientId) {
            $recipient = $this->userRepository->findById($recipientId);
            if ($recipient !== null &&
                $recipient->getNotificationPreferences()->isSmsEnabled() &&
                $recipient->hasVerifiedPhone()) {
                $validRecipients++;
            }
        }

        if ($validRecipients === 0) {
            $this->logger->info('Bulk SMS permission denied: no valid recipients', [
                'sender_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Bulk SMS permission granted', [
            'sender_id' => $user->getId()->toString(),
            'valid_recipients' => $validRecipients,
        ]);

        return true;
    }

    public function canSendBulkPush(User $user, array $recipientIds): bool
    {
        if ($user === null) {
            $this->logger->warning('Bulk push permission denied: null sender');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Bulk push permission denied: sender inactive', [
                'sender_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('notification', 'push', 'send')) {
            $this->logger->info('Bulk push permission denied: no send permission', [
                'sender_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('notification', 'push', 'bulk_send')) {
            $this->logger->info('Bulk push permission denied: no bulk send permission', [
                'sender_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $validRecipients = 0;
        foreach ($recipientIds as $recipientId) {
            $recipient = $this->userRepository->findById($recipientId);
            if ($recipient !== null &&
                $recipient->getNotificationPreferences()->isPushEnabled() &&
                $recipient->hasPushToken()) {
                $validRecipients++;
            }
        }

        if ($validRecipients === 0) {
            $this->logger->info('Bulk push permission denied: no valid recipients', [
                'sender_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Bulk push permission granted', [
            'sender_id' => $user->getId()->toString(),
            'valid_recipients' => $validRecipients,
        ]);

        return true;
    }
}
