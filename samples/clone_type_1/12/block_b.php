<?php

declare(strict_types=1);

namespace App\Email\Templates;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailConfig;
use App\Service\TemplateRenderer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class ActivationEmailSender
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TemplateRenderer $renderer,
        private readonly MailerInterface $mailer,
        private readonly EmailConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendActivationEmail(int $userId): void
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            $this->logger->warning('User not found for activation email', [
                'user_id' => $userId,
            ]);
            return;
        }

        if ($user->isActivated()) {
            $this->logger->info('User already activated, skipping activation', [
                'user_id' => $userId,
            ]);
            return;
        }

        $templateData = $this->prepareTemplateData($user);
        $htmlContent = $this->renderer->render('emails/activation.html.twig', $templateData);
        $textContent = $this->renderer->render('emails/activation.txt.twig', $templateData);

        $email = (new Email())
            ->from($this->config->getFromAddress())
            ->to($user->getEmail())
            ->subject('Activate Your Account - Complete Registration')
            ->html($htmlContent)
            ->text($textContent);

        try {
            $this->mailer->send($email);
            $this->logger->info('Activation email sent successfully', [
                'user_id' => $userId,
                'email' => $user->getEmail(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send activation email', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function prepareTemplateData(User $user): array
    {
        return [
            'user' => $user,
            'first_name' => $user->getFirstName(),
            'activation_token' => $user->getActivationToken(),
            'activation_url' => $this->buildActivationUrl($user),
            'support_email' => $this->config->getSupportEmail(),
            'company_name' => $this->config->getCompanyName(),
            'expiry_hours' => 72,
        ];
    }

    private function buildActivationUrl(User $user): string
    {
        return sprintf(
            '%s/activate-account?token=%s',
            $this->config->getAppUrl(),
            $user->getActivationToken()
        );
    }
}
