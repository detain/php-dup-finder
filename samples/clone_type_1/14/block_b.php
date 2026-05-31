<?php

declare(strict_types=1);

namespace App\Notifications\Email;

use App\Entity\Member;
use App\Repository\MemberRepository;
use App\Service\MailerService;
use App\Service\EmailTemplate;
use Psr\Log\LoggerInterface;

final class MemberVerificationEmailService
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly MailerService $mailerService,
        private readonly EmailTemplate $emailTemplate,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendVerificationEmail(int $memberId): bool
    {
        $member = $this->memberRepository->findById($memberId);

        if ($member === null) {
            $this->logger->error('Cannot send verification email - member not found', [
                'member_id' => $memberId,
            ]);
            return false;
        }

        if ($member->isEmailVerified()) {
            $this->logger->info('Email already verified, skipping verification email', [
                'member_id' => $memberId,
            ]);
            return false;
        }

        $templateData = $this->prepareTemplateData($member);
        $htmlBody = $this->emailTemplate->render('emails/verification.html.twig', $templateData);
        $textBody = $this->emailTemplate->render('emails/verification.txt.twig', $templateData);

        $subject = 'Verify Your Email Address - Action Required';

        try {
            $this->mailerService->send(
                $member->getEmail(),
                $subject,
                $htmlBody,
                $textBody
            );

            $this->logger->info('Verification email sent successfully', [
                'member_id' => $memberId,
                'email' => $member->getEmail(),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send verification email', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function prepareTemplateData(Member $member): array
    {
        return [
            'member_id' => $member->getId(),
            'name' => $member->getName(),
            'email' => $member->getEmail(),
            'verification_token' => $member->getEmailVerificationToken(),
            'verification_link' => $this->buildVerificationLink($member),
            'expires_hours' => 48,
            'support_email' => 'support@example.com',
            'company_name' => 'Example Corp',
        ];
    }

    private function buildVerificationLink(Member $member): string
    {
        return sprintf(
            'https://app.example.com/verify-email?user_id=%d&token=%s',
            $member->getId(),
            urlencode($member->getEmailVerificationToken())
        );
    }
}
