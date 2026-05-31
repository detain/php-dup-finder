<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Infrastructure\Config\ConfigService;

/**
 * Email notification service.
 * The ConfigService is manually injected here, duplicated across
 * all notification and external service classes.
 */
class EmailService
{
    private ConfigService $config;
    private \Swift_Mailer $mailer;

    public function __construct(ConfigService $config, \Swift_Mailer $mailer)
    {
        $this->config = $config;
        $this->mailer = $mailer;
    }

    public function sendOrderConfirmation(string $recipientEmail, array $orderData): bool
    {
        $template = $this->loadTemplate('order_confirmation', $orderData);

        $message = (new \Swift_Message())
            ->setSubject($this->getSubject('order_confirmation', $orderData))
            ->setFrom([
                $this->config->get('email.from_address') => $this->config->get('email.from_name')
            ])
            ->setTo([$recipientEmail])
            ->setBody($template, 'text/html');

        $this->logEmailSend($recipientEmail, 'order_confirmation', $orderData);

        return $this->mailer->send($message) > 0;
    }

    public function sendPasswordReset(string $recipientEmail, string $resetToken): bool
    {
        $resetUrl = $this->config->get('app.url')
            . '/auth/reset-password?token=' . $resetToken;

        $template = $this->loadTemplate('password_reset', [
            'reset_url' => $resetUrl,
            'expiry_hours' => $this->config->get('auth.password_reset_expiry_hours'),
        ]);

        $message = (new \Swift_Message())
            ->setSubject('Reset Your Password')
            ->setFrom([
                $this->config->get('email.from_address') => $this->config->get('email.from_name')
            ])
            ->setTo([$recipientEmail])
            ->setBody($template, 'text/html');

        $this->logEmailSend($recipientEmail, 'password_reset', ['token_hash' => '***']);

        return $this->mailer->send($message) > 0;
    }

    public function sendWelcomeEmail(string $recipientEmail, string $firstName): bool
    {
        $template = $this->loadTemplate('welcome', [
            'first_name' => $firstName,
            'getting_started_url' => $this->config->get('app.url') . '/getting-started',
        ]);

        $message = (new \Swift_Message())
            ->setSubject('Welcome to ' . $this->config->get('app.name'))
            ->setFrom([
                $this->config->get('email.from_address') => $this->config->get('email.from_name')
            ])
            ->setTo([$recipientEmail])
            ->setBody($template, 'text/html');

        $this->logEmailSend($recipientEmail, 'welcome', []);

        return $this->mailer->send($message) > 0;
    }

    public function sendRefundNotification(string $recipientEmail, array $refundData): bool
    {
        $template = $this->loadTemplate('refund_notification', $refundData);

        $message = (new \Swift_Message())
            ->setSubject('Your Refund Has Been Processed')
            ->setFrom([
                $this->config->get('email.from_address') => $this->config->get('email.from_name')
            ])
            ->setTo([$recipientEmail])
            ->setBody($template, 'text/html');

        $this->logEmailSend($recipientEmail, 'refund_notification', $refundData);

        return $this->mailer->send($message) > 0;
    }

    public function sendAccountSuspensionNotice(string $recipientEmail, string $reason): bool
    {
        $template = $this->loadTemplate('account_suspension', [
            'reason' => $reason,
            'support_email' => $this->config->get('support.email'),
            'appeal_url' => $this->config->get('app.url') . '/support/appeal',
        ]);

        $message = (new \Swift_Message())
            ->setSubject('Important: Your Account Has Been Suspended')
            ->setFrom([
                $this->config->get('email.from_address') => $this->config->get('email.from_name')
            ])
            ->setTo([$recipientEmail])
            ->setBody($template, 'text/html');

        $this->logEmailSend($recipientEmail, 'account_suspension', ['reason' => $reason]);

        return $this->mailer->send($message) > 0;
    }

    private function loadTemplate(string $templateName, array $data): string
    {
        $templatePath = $this->config->get('email.template_path')
            . '/' . $templateName . '.html.twig';

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Email template not found: {$templateName}");
        }

        $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(
            $this->config->get('email.template_path')
        ));

        return $twig->render($templateName . '.html.twig', $data);
    }

    private function getSubject(string $templateName, array $data): string
    {
        $subjects = [
            'order_confirmation' => 'Order Confirmation - ' . ($data['order_number'] ?? ''),
            'password_reset' => 'Reset Your Password',
            'welcome' => 'Welcome to ' . $this->config->get('app.name'),
            'refund_notification' => 'Your Refund Has Been Processed',
            'account_suspension' => 'Important: Your Account Has Been Suspended',
        ];

        return $subjects[$templateName] ?? 'Message from ' . $this->config->get('app.name');
    }

    private function logEmailSend(string $recipient, string $template, array $data): void
    {
        error_log(sprintf(
            "[Email] Sent %s to %s",
            $template,
            $recipient
        ));
    }
}
