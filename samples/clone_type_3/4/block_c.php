<?php

declare(strict_types=1);

namespace App\Notifications\Push;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PushClient;
use App\Service\PushTemplate;
use Psr\Log\LoggerInterface;

final class UserPushNotificationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PushClient $pushClient,
        private readonly PushTemplate $pushTemplate,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendWelcomeNotification(int $userId): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            $this->logger->error('Cannot send welcome push - user not found', [
                'user_id' => $userId,
            ]);
            return false;
        }

        $deviceTokens = $user->getDeviceTokens();

        if (empty($deviceTokens)) {
            $this->logger->warning('Cannot send welcome push - no device tokens', [
                'user_id' => $userId,
            ]);
            return false;
        }

        $templateData = [
            'user_name' => $user->getName(),
            'title' => 'Welcome to Example Corp!',
            'body' => sprintf('Hi %s, your account is ready. Activate now to get started.', $user->getName()),
            'action_link' => 'example://activate',
            'icon' => 'welcome_icon.png',
            'badge' => 1,
        ];

        $notification = $this->pushTemplate->render('welcome.json.twig', $templateData);

        $successCount = 0;
        $failureCount = 0;

        foreach ($deviceTokens as $token) {
            $result = $this->pushClient->send($token, $notification);

            if ($result) {
                $successCount++;
            } else {
                $failureCount++;
                if ($this->isInvalidTokenError($this->pushClient->getLastError())) {
                    $user->removeDeviceToken($token);
                }
            }
        }

        if ($failureCount > 0 && $successCount === 0) {
            $this->logger->warning('All welcome push notifications failed', [
                'user_id' => $userId,
                'failure_count' => $failureCount,
            ]);
            return false;
        }

        if ($successCount > 0) {
            $this->logger->info('Welcome push notifications sent', [
                'user_id' => $userId,
                'success_count' => $successCount,
                'failure_count' => $failureCount,
            ]);
        }

        if (!empty($user->getDeviceTokens())) {
            $this->userRepository->save($user);
        }

        return $successCount > 0;
    }

    public function sendPasswordResetNotification(int $userId): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            $this->logger->error('Cannot send password reset push - user not found', [
                'user_id' => $userId,
            ]);
            return false;
        }

        $deviceTokens = $user->getDeviceTokens();

        if (empty($deviceTokens)) {
            return false;
        }

        $templateData = [
            'title' => 'Password Reset Request',
            'body' => 'Tap here to reset your password securely.',
            'action_link' => 'example://reset-password',
            'icon' => 'reset_icon.png',
            'badge' => 1,
            'sound' => 'default',
        ];

        $notification = $this->pushTemplate->render('password_reset.json.twig', $templateData);

        $successCount = 0;
        foreach ($deviceTokens as $token) {
            if ($this->pushClient->send($token, $notification)) {
                $successCount++;
            }
        }

        return $successCount > 0;
    }

    private function isInvalidTokenError(?string $error): bool
    {
        return $error !== null && str_contains($error, 'InvalidRegistration');
    }
}
