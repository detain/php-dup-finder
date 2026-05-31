<?php

declare(strict_types=1);

namespace App\Notifications\Email;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\MailerService;
use App\Service\EmailTemplate;
use Psr\Log\LoggerInterface;

final class AccountVerificationEmailService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MailerService $mailerService,
        private readonly EmailTemplate $emailTemplate,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendVerificationEmail(int $userId): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            $this->logger->error('Cannot send verification email - user not found', [
                'user_id' => $userId,
            ]);
            return false;
        }

        if ($user->isEmailVerified()) {
            $this->logger->info('Email already verified, skipping verification email', [
                'user_id' => $userId,
            ]);
            return false;
        }

        $templateData = $this->prepareTemplateData($user);
        $htmlBody = $this->emailTemplate->render('emails/verification.html.twig', $templateData);
        $textBody = $this->emailTemplate->render('emails/verification.txt.twig', $templateData);

        $subject = 'Verify Your Email Address - Action Required';

        try {
            $this->mailerService->send(
                $user->getEmail(),
                $subject,
                $htmlBody,
                $textBody
            );

            $this->logger->info('Verification email sent successfully', [
                'user_id' => $userId,
                'email' => $user->getEmail(),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send verification email', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function prepareTemplateData(User $user): array
    {
        return [
            'user_id' => $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'verification_token' => $user->getEmailVerificationToken(),
            'verification_link' => $this->buildVerificationLink($user),
            'expires_hours' => 48,
            'support_email' => 'support@example.com',
            'company_name' => 'Example Corp',
        ];
    }

    private function buildVerificationLink(User $user): string
    {
        return sprintf(
            'https://app.example.com/verify-email?user_id=%d&token=%s',
            $user->getId(),
            urlencode($user->getEmailVerificationToken())
        );
    }
}
