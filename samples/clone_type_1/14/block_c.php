<?php

declare(strict_types=1);

namespace App\Notifications\Email;

use App\Entity\Subscriber;
use App\Repository\SubscriberRepository;
use App\Service\MailerService;
use App\Service\EmailTemplate;
use Psr\Log\LoggerInterface;

final class SubscriberVerificationEmailService
{
    public function __construct(
        private readonly SubscriberRepository $subscriberRepository,
        private readonly MailerService $mailerService,
        private readonly EmailTemplate $emailTemplate,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendVerificationEmail(int $subscriberId): bool
    {
        $subscriber = $this->subscriberRepository->findById($subscriberId);

        if ($subscriber === null) {
            $this->logger->error('Cannot send verification email - subscriber not found', [
                'subscriber_id' => $subscriberId,
            ]);
            return false;
        }

        if ($subscriber->isEmailVerified()) {
            $this->logger->info('Email already verified, skipping verification email', [
                'subscriber_id' => $subscriberId,
            ]);
            return false;
        }

        $templateData = $this->prepareTemplateData($subscriber);
        $htmlBody = $this->emailTemplate->render('emails/verification.html.twig', $templateData);
        $textBody = $this->emailTemplate->render('emails/verification.txt.twig', $templateData);

        $subject = 'Verify Your Email Address - Action Required';

        try {
            $this->mailerService->send(
                $subscriber->getEmail(),
                $subject,
                $htmlBody,
                $textBody
            );

            $this->logger->info('Verification email sent successfully', [
                'subscriber_id' => $subscriberId,
                'email' => $subscriber->getEmail(),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send verification email', [
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function prepareTemplateData(Subscriber $subscriber): array
    {
        return [
            'subscriber_id' => $subscriber->getId(),
            'name' => $subscriber->getName(),
            'email' => $subscriber->getEmail(),
            'verification_token' => $subscriber->getEmailVerificationToken(),
            'verification_link' => $this->buildVerificationLink($subscriber),
            'expires_hours' => 48,
            'support_email' => 'support@example.com',
            'company_name' => 'Example Corp',
        ];
    }

    private function buildVerificationLink(Subscriber $subscriber): string
    {
        return sprintf(
            'https://app.example.com/verify-email?user_id=%d&token=%s',
            $subscriber->getId(),
            urlencode($subscriber->getEmailVerificationToken())
        );
    }
}
