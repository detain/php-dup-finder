<?php

declare(strict_types=1);

namespace App\Notifications\Email;

use App\Entity\PersonInterface;
use App\Repository\PersonRepositoryInterface;
use App\Service\MailerService;
use App\Service\EmailTemplate;
use Psr\Log\LoggerInterface;

final class VerificationEmailService
{
    public function __construct(
        private readonly PersonRepositoryInterface $personRepository,
        private readonly MailerService $mailerService,
        private readonly EmailTemplate $emailTemplate,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendVerificationEmail(int $personId): bool
    {
        $person = $this->personRepository->findById($personId);

        if ($person === null) {
            $this->logger->error('Cannot send verification email - person not found', [
                'person_id' => $personId,
            ]);
            return false;
        }

        if ($person->isEmailVerified()) {
            $this->logger->info('Email already verified, skipping verification email', [
                'person_id' => $personId,
            ]);
            return false;
        }

        $templateData = $this->prepareTemplateData($person);
        $htmlBody = $this->emailTemplate->render('emails/verification.html.twig', $templateData);
        $textBody = $this->emailTemplate->render('emails/verification.txt.twig', $templateData);

        $subject = 'Verify Your Email Address - Action Required';

        try {
            $this->mailerService->send(
                $person->getEmail(),
                $subject,
                $htmlBody,
                $textBody
            );

            $this->logger->info('Verification email sent successfully', [
                'person_id' => $personId,
                'email' => $person->getEmail(),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send verification email', [
                'person_id' => $personId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function prepareTemplateData(PersonInterface $person): array
    {
        return [
            'person_id' => $person->getId(),
            'name' => $person->getName(),
            'email' => $person->getEmail(),
            'verification_token' => $person->getEmailVerificationToken(),
            'verification_link' => $this->buildVerificationLink($person),
            'expires_hours' => 48,
            'support_email' => 'support@example.com',
            'company_name' => 'Example Corp',
        ];
    }

    private function buildVerificationLink(PersonInterface $person): string
    {
        return sprintf(
            'https://app.example.com/verify-email?user_id=%d&token=%s',
            $person->getId(),
            urlencode($person->getEmailVerificationToken())
        );
    }
}
