<?php

declare(strict_types=1);

namespace App\Notifications\Sms;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\SmsClient;
use App\Service\SmsTemplate;
use Psr\Log\LoggerInterface;

final class UserSmsNotificationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly SmsClient $smsClient,
        private readonly SmsTemplate $smsTemplate,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendWelcomeNotification(int $userId): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            $this->logger->error('Cannot send welcome SMS - user not found', [
                'user_id' => $userId,
            ]);
            return false;
        }

        if (!$user->getPhone()) {
            $this->logger->warning('Cannot send welcome SMS - no phone number', [
                'user_id' => $userId,
            ]);
            return false;
        }

        $templateData = [
            'user_name' => $user->getName(),
            'activation_code' => $this->generateActivationCode(),
        ];

        $message = $this->smsTemplate->render('welcome.txt.twig', $templateData);

        $maxAttempts = 3;
        $attempt = 0;
        $success = false;

        while ($attempt < $maxAttempts && !$success) {
            $success = $this->smsClient->send(
                $user->getPhone(),
                $message
            );
            $attempt++;

            if (!$success) {
                usleep(500000);
            }
        }

        if ($success) {
            $user->setSmsVerificationCode($templateData['activation_code']);
            $user->setSmsVerificationExpiresAt(new \DateTimeImmutable('+10 minutes'));
            $this->userRepository->save($user);

            $this->logger->info('Welcome SMS sent successfully', [
                'user_id' => $userId,
                'phone' => $this->maskPhone($user->getPhone()),
                'attempts' => $attempt,
            ]);
        } else {
            $this->logger->warning('Failed to send welcome SMS after retries', [
                'user_id' => $userId,
                'attempts' => $attempt,
            ]);
        }

        return $success;
    }

    public function sendPasswordResetNotification(int $userId): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            $this->logger->error('Cannot send password reset SMS - user not found', [
                'user_id' => $userId,
            ]);
            return false;
        }

        if (!$user->getPhone()) {
            return false;
        }

        $resetCode = $this->generateResetCode();

        $templateData = [
            'user_name' => $user->getName(),
            'reset_code' => $resetCode,
            'expiry_minutes' => 10,
        ];

        $message = $this->smsTemplate->render('password_reset.txt.twig', $templateData);

        $maxAttempts = 3;
        $attempt = 0;
        $success = false;

        while ($attempt < $maxAttempts && !$success) {
            $success = $this->smsClient->send(
                $user->getPhone(),
                $message
            );
            $attempt++;

            if (!$success) {
                usleep(500000);
            }
        }

        if ($success) {
            $user->setPasswordResetSmsCode($resetCode);
            $user->setPasswordResetSmsExpiresAt(new \DateTimeImmutable('+10 minutes'));
            $this->userRepository->save($user);

            $this->logger->info('Password reset SMS sent', [
                'user_id' => $userId,
            ]);
        }

        return $success;
    }

    private function generateActivationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function generateResetCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function maskPhone(?string $phone): string
    {
        if ($phone === null) {
            return '***';
        }
        return substr($phone, 0, 3) . '***' . substr($phone, -3);
    }
}
