<?php

declare(strict_types=1);

namespace App\Notifications\Email;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailClient;
use App\Service\EmailTemplate;
use Psr\Log\LoggerInterface;

final class UserNotificationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EmailClient $emailClient,
        private readonly EmailTemplate $emailTemplate,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendWelcomeNotification(int $userId): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            $this->logger->error('Cannot send welcome notification - user not found', [
                'user_id' => $userId,
            ]);
            return false;
        }

        $templateData = [
            'user_name' => $user->getName(),
            'user_email' => $user->getEmail(),
            'activation_link' => $this->buildActivationLink($user),
            'support_email' => 'support@example.com',
            'company_name' => 'Example Corp',
        ];

        $subject = 'Welcome to Example Corp - Please Activate Your Account';
        $htmlBody = $this->emailTemplate->render('welcome.html.twig', $templateData);
        $textBody = $this->emailTemplate->render('welcome.txt.twig', $templateData);

        $success = $this->emailClient->send(
            $user->getEmail(),
            $subject,
            $htmlBody,
            $textBody
        );

        if ($success) {
            $this->logger->info('Welcome notification sent successfully', [
                'user_id' => $userId,
                'email' => $user->getEmail(),
            ]);
        } else {
            $this->logger->warning('Failed to send welcome notification', [
                'user_id' => $userId,
            ]);
        }

        return $success;
    }

    public function sendPasswordResetNotification(int $userId): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            $this->logger->error('Cannot send password reset - user not found', [
                'user_id' => $userId,
            ]);
            return false;
        }

        $resetToken = $this->generateResetToken($user);

        $templateData = [
            'user_name' => $user->getName(),
            'reset_link' => $this->buildResetLink($user, $resetToken),
            'expiry_hours' => 24,
            'support_email' => 'support@example.com',
            'company_name' => 'Example Corp',
        ];

        $subject = 'Password Reset Request - Example Corp';
        $htmlBody = $this->emailTemplate->render('password_reset.html.twig', $templateData);
        $textBody = $this->emailTemplate->render('password_reset.txt.twig', $templateData);

        $success = $this->emailClient->send(
            $user->getEmail(),
            $subject,
            $htmlBody,
            $textBody
        );

        if ($success) {
            $user->setPasswordResetToken($resetToken);
            $user->setPasswordResetExpiresAt(new \DateTimeImmutable('+24 hours'));
            $this->userRepository->save($user);

            $this->logger->info('Password reset notification sent', [
                'user_id' => $userId,
            ]);
        }

        return $success;
    }

    private function generateResetToken(User $user): string
    {
        return bin2hex(random_bytes(32));
    }

    private function buildActivationLink(User $user): string
    {
        return sprintf(
            'https://example.com/activate?token=%s',
            urlencode($user->getActivationToken())
        );
    }

    private function buildResetLink(User $user, string $resetToken): string
    {
        return sprintf(
            'https://example.com/reset-password?token=%s',
            urlencode($resetToken)
        );
    }
}
