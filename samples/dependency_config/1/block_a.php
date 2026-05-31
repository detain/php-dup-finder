<?php
declare(strict_types=1);

namespace Notifications\Email;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

final class EmailNotificationHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly array $config,
        private readonly TemplateRenderer $renderer,
        private readonly EmailQueue $queue
    ) {}

    public function sendOrderConfirmation(Order $order): SendResult
    {
        $customer = $order->getCustomer();
        $template = $this->config['notifications']['email']['templates']['order_confirmation'] ?? 'order_confirmation';

        $this->logger->info('Sending order confirmation email', [
            'order_id' => $order->getId(),
            'customer_email' => $customer->getEmail()
        ]);

        try {
            $emailBody = $this->renderer->render($template, [
                'order' => $order->toArray(),
                'customer' => $customer->toArray(),
                'items' => $this->formatOrderItems($order),
                'total' => $this->formatCurrency($order->getTotal(), $order->getCurrency())
            ]);

            $email = (new Email())
                ->from(new Address(
                    $this->config['notifications']['email']['from']['address'],
                    $this->config['notifications']['email']['from']['name']
                ))
                ->to($customer->getEmail())
                ->subject('Order Confirmation - #' . $order->getId())
                ->html($emailBody);

            $this->mailer->send($email);

            $this->logger->info('Order confirmation email sent', [
                'order_id' => $order->getId()
            ]);

            return SendResult::success();

        } catch (\Exception $e) {
            $this->logger->error('Failed to send order confirmation', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);

            // Queue for retry
            $this->queue->publish('email_notification', [
                'type' => 'order_confirmation',
                'order_id' => $order->getId(),
                'customer_email' => $customer->getEmail(),
                'attempt' => 1
            ]);

            return SendResult::failure($e->getMessage());
        }
    }

    public function sendPasswordReset(User $user, string $resetToken): SendResult
    {
        $template = $this->config['notifications']['email']['templates']['password_reset'] ?? 'password_reset';
        $expiryHours = $this->config['auth']['password_reset']['token_expiry_hours'] ?? 24;

        $this->logger->info('Sending password reset email', [
            'user_id' => $user->getId()
        ]);

        try {
            $emailBody = $this->renderer->render($template, [
                'user' => $user->toArray(),
                'reset_link' => $this->buildResetLink($resetToken, $expiryHours),
                'expiry_hours' => $expiryHours
            ]);

            $email = (new Email())
                ->from(new Address(
                    $this->config['notifications']['email']['from']['address'],
                    $this->config['notifications']['email']['from']['name']
                ))
                ->to($user->getEmail())
                ->subject('Password Reset Request')
                ->html($emailBody);

            $this->mailer->send($email);

            return SendResult::success();

        } catch (\Exception $e) {
            $this->logger->error('Failed to send password reset email', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            return SendResult::failure($e->getMessage());
        }
    }

    public function sendWelcomeEmail(Customer $customer): SendResult
    {
        $template = $this->config['notifications']['email']['templates']['welcome'] ?? 'welcome';
        $includePromotion = $this->config['notifications']['email']['welcome']['include_promotion'] ?? true;
        $promotionCode = $this->config['notifications']['email']['welcome']['promotion_code'] ?? 'WELCOME10';

        $this->logger->info('Sending welcome email', [
            'customer_id' => $customer->getId()
        ]);

        try {
            $context = [
                'customer' => $customer->toArray(),
                'first_name' => $customer->getFirstName()
            ];

            if ($includePromotion) {
                $context['promotion_code'] = $promotionCode;
                $context['promotion_amount'] = $this->config['notifications']['email']['welcome']['promotion_amount'] ?? 10;
            }

            $emailBody = $this->renderer->render($template, $context);

            $email = (new Email())
                ->from(new Address(
                    $this->config['notifications']['email']['from']['address'],
                    $this->config['notifications']['email']['from']['name']
                ))
                ->to($customer->getEmail())
                ->subject('Welcome to ' . ($this->config['app']['name'] ?? 'Our Platform'))
                ->html($emailBody);

            $this->mailer->send($email);

            return SendResult::success();

        } catch (\Exception $e) {
            $this->logger->error('Failed to send welcome email', [
                'customer_id' => $customer->getId()
            ]);
            return SendResult::failure($e->getMessage());
        }
    }

    private function buildResetLink(string $token, int $expiryHours): string
    {
        $baseUrl = $this->config['app']['url'] ?? 'https://example.com';
        return "{$baseUrl}/reset-password?token={$token}&expires=" . (time() + $expiryHours * 3600);
    }

    private function formatOrderItems(Order $order): array
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = [
                'name' => $item->getProduct()->getName(),
                'quantity' => $item->getQuantity(),
                'price' => $this->formatCurrency($item->getPrice(), $order->getCurrency())
            ];
        }
        return $items;
    }

    private function formatCurrency(int $amount, string $currency): string
    {
        return number_format($amount / 100, 2) . ' ' . strtoupper($currency);
    }
}
