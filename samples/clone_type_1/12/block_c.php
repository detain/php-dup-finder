<?php

declare(strict_types=1);

namespace App\Email\Templates;

use App\Entity\Member;
use App\Repository\MemberRepository;
use App\Service\EmailConfig;
use App\Service\TemplateRenderer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class OnboardingEmailSender
{
    public function __construct(
        private readonly MemberRepository $members,
        private readonly TemplateRenderer $renderer,
        private readonly MailerInterface $mailer,
        private readonly EmailConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendOnboardingEmail(int $memberId): void
    {
        $member = $this->members->findById($memberId);

        if ($member === null) {
            $this->logger->warning('Member not found for onboarding email', [
                'member_id' => $memberId,
            ]);
            return;
        }

        if ($member->hasCompletedOnboarding()) {
            $this->logger->info('Member already onboarded, skipping onboarding', [
                'member_id' => $memberId,
            ]);
            return;
        }

        $templateData = $this->prepareTemplateData($member);
        $htmlContent = $this->renderer->render('emails/onboarding.html.twig', $templateData);
        $textContent = $this->renderer->render('emails/onboarding.txt.twig', $templateData);

        $email = (new Email())
            ->from($this->config->getFromAddress())
            ->to($member->getEmail())
            ->subject('Get Started - Complete Your Member Profile')
            ->html($htmlContent)
            ->text($textContent);

        try {
            $this->mailer->send($email);
            $this->logger->info('Onboarding email sent successfully', [
                'member_id' => $memberId,
                'email' => $member->getEmail(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send onboarding email', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function prepareTemplateData(Member $member): array
    {
        return [
            'member' => $member,
            'first_name' => $member->getFirstName(),
            'onboarding_token' => $member->getOnboardingToken(),
            'onboarding_url' => $this->buildOnboardingUrl($member),
            'support_email' => $this->config->getSupportEmail(),
            'company_name' => $this->config->getCompanyName(),
            'expiry_hours' => 168,
        ];
    }

    private function buildOnboardingUrl(Member $member): string
    {
        return sprintf(
            '%s/onboarding?token=%s',
            $this->config->getAppUrl(),
            $member->getOnboardingToken()
        );
    }
}
