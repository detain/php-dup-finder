<?php

declare(strict_types=1);

namespace App\Email;

use App\Entity\Person;
use App\Repository\PersonRepository;
use App\Service\EmailConfig;
use App\Service\TemplateRenderer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class UserEmailSender
{
    public function __construct(
        private readonly PersonRepository $persons,
        private readonly TemplateRenderer $renderer,
        private readonly MailerInterface $mailer,
        private readonly EmailConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendEmail(int $personId, EmailType $type): void
    {
        $person = $this->persons->findById($personId);

        if ($person === null) {
            $this->logger->warning("Person not found for {$type->value} email", [
                'person_id' => $personId,
            ]);
            return;
        }

        if ($this->shouldSkipSending($person, $type)) {
            return;
        }

        $templateData = $this->prepareTemplateData($person, $type);
        $htmlContent = $this->renderer->render($type->getHtmlTemplate(), $templateData);
        $textContent = $this->renderer->render($type->getTextTemplate(), $templateData);

        $email = (new Email())
            ->from($this->config->getFromAddress())
            ->to($person->getEmail())
            ->subject($type->getSubject())
            ->html($htmlContent)
            ->text($textContent);

        try {
            $this->mailer->send($email);
            $this->logger->info("{$type->value} email sent successfully", [
                'person_id' => $personId,
                'email' => $person->getEmail(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to send {$type->value} email", [
                'person_id' => $personId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function shouldSkipSending(Person $person, EmailType $type): bool
    {
        return match ($type) {
            EmailType::Welcome => $person->isEmailVerified(),
            EmailType::Activation => $person->isActivated(),
            EmailType::Onboarding => $person->hasCompletedOnboarding(),
        };
    }

    private function prepareTemplateData(Person $person, EmailType $type): array
    {
        return [
            'person' => $person,
            'first_name' => $person->getFirstName(),
            'token' => $type->getToken($person),
            'action_url' => $type->getActionUrl($this->config->getAppUrl(), $person),
            'support_email' => $this->config->getSupportEmail(),
            'company_name' => $this->config->getCompanyName(),
            'expiry_hours' => $type->getExpiryHours(),
        ];
    }
}

enum EmailType: string
{
    case Welcome = 'welcome';
    case Activation = 'activation';
    case Onboarding = 'onboarding';

    public function getHtmlTemplate(): string
    {
        return match ($this) {
            self::Welcome => 'emails/welcome.html.twig',
            self::Activation => 'emails/activation.html.twig',
            self::Onboarding => 'emails/onboarding.html.twig',
        };
    }

    public function getTextTemplate(): string
    {
        return match ($this) {
            self::Welcome => 'emails/welcome.txt.twig',
            self::Activation => 'emails/activation.txt.twig',
            self::Onboarding => 'emails/onboarding.txt.twig',
        };
    }

    public function getSubject(): string
    {
        return match ($this) {
            self::Welcome => 'Welcome to Our Platform - Please Verify Your Email',
            self::Activation => 'Activate Your Account - Complete Registration',
            self::Onboarding => 'Get Started - Complete Your Member Profile',
        };
    }

    public function getToken(Person $person): string
    {
        return match ($this) {
            self::Welcome => $person->getEmailVerificationToken(),
            self::Activation => $person->getActivationToken(),
            self::Onboarding => $person->getOnboardingToken(),
        };
    }

    public function getActionUrl(string $appUrl, Person $person): string
    {
        $token = $this->getToken($person);
        return match ($this) {
            self::Welcome => "{$appUrl}/verify-email?token={$token}",
            self::Activation => "{$appUrl}/activate-account?token={$token}",
            self::Onboarding => "{$appUrl}/onboarding?token={$token}",
        };
    }

    public function getExpiryHours(): int
    {
        return match ($this) {
            self::Welcome => 48,
            self::Activation => 72,
            self::Onboarding => 168,
        };
    }
}
