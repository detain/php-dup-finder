<?php

declare(strict_types=1);

namespace App\Email\Templates;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Service\EmailConfig;
use App\Service\TemplateRenderer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class WelcomeEmailSender
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly TemplateRenderer $renderer,
        private readonly MailerInterface $mailer,
        private readonly EmailConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendWelcomeEmail(int $customerId): void
    {
        $customer = $this->customers->findById($customerId);

        if ($customer === null) {
            $this->logger->warning('Customer not found for welcome email', [
                'customer_id' => $customerId,
            ]);
            return;
        }

        if ($customer->isEmailVerified()) {
            $this->logger->info('Customer already verified, skipping welcome', [
                'customer_id' => $customerId,
            ]);
            return;
        }

        $templateData = $this->prepareTemplateData($customer);
        $htmlContent = $this->renderer->render('emails/welcome.html.twig', $templateData);
        $textContent = $this->renderer->render('emails/welcome.txt.twig', $templateData);

        $email = (new Email())
            ->from($this->config->getFromAddress())
            ->to($customer->getEmail())
            ->subject('Welcome to Our Platform - Please Verify Your Email')
            ->html($htmlContent)
            ->text($textContent);

        try {
            $this->mailer->send($email);
            $this->logger->info('Welcome email sent successfully', [
                'customer_id' => $customerId,
                'email' => $customer->getEmail(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send welcome email', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function prepareTemplateData(Customer $customer): array
    {
        return [
            'customer' => $customer,
            'first_name' => $customer->getFirstName(),
            'verification_token' => $customer->getEmailVerificationToken(),
            'verification_url' => $this->buildVerificationUrl($customer),
            'support_email' => $this->config->getSupportEmail(),
            'company_name' => $this->config->getCompanyName(),
            'expiry_hours' => 48,
        ];
    }

    private function buildVerificationUrl(Customer $customer): string
    {
        return sprintf(
            '%s/verify-email?token=%s',
            $this->config->getAppUrl(),
            $customer->getEmailVerificationToken()
        );
    }
}
