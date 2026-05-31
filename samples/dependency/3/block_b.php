<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Infrastructure\Config\ConfigService;

/**
 * SMS notification service.
 * The ConfigService is manually injected here, duplicated from
 * EmailService and other notification services.
 */
class SmsService
{
    private ConfigService $config;
    private SmsProviderInterface $provider;

    public function __construct(ConfigService $config, SmsProviderInterface $provider)
    {
        $this->config = $config;
        $this->provider = $provider;
    }

    public function sendVerificationCode(string $phoneNumber, string $code): bool
    {
        $message = "Your verification code is: {$code}. " .
                   "This code expires in " .
                   $this->config->get('sms.verification_code_expiry_minutes') . " minutes.";

        return $this->send($phoneNumber, $message, 'verification');
    }

    public function sendOrderUpdate(string $phoneNumber, array $orderData): bool
    {
        $statusMessage = $this->getStatusMessage($orderData['status']);

        $message = sprintf(
            "Order %s: %s. Track at: %s/order/%s",
            $orderData['order_number'],
            $statusMessage,
            $this->config->get('app.url'),
            $orderData['order_id']
        );

        return $this->send($phoneNumber, $message, 'order_update');
    }

    public function sendShippingNotification(string $phoneNumber, array $shippingData): bool
    {
        $message = sprintf(
            "Your order %s has been shipped! Carrier: %s, Tracking: %s. " .
            "Track at: %s/track/%s",
            $shippingData['order_number'],
            $shippingData['carrier'],
            $shippingData['tracking_number'],
            $this->config->get('app.url'),
            $shippingData['tracking_number']
        );

        return $this->send($phoneNumber, $message, 'shipping_notification');
    }

    public function sendDeliveryNotification(string $phoneNumber, array $deliveryData): bool
    {
        $message = sprintf(
            "Your order %s is out for delivery today! " .
            "Expected delivery window: %s - %s",
            $deliveryData['order_number'],
            $deliveryData['delivery_window_start'],
            $deliveryData['delivery_window_end']
        );

        return $this->send($phoneNumber, $message, 'delivery_notification');
    }

    public function sendPaymentReceived(string $phoneNumber, array $paymentData): bool
    {
        $message = sprintf(
            "Payment of %s %s received for order %s. Thank you!",
            $paymentData['currency'],
            number_format($paymentData['amount'], 2),
            $paymentData['order_number']
        );

        return $this->send($phoneNumber, $message, 'payment_received');
    }

    public function sendAccountAlert(string $phoneNumber, string $alertType, array $alertData): bool
    {
        $message = match ($alertType) {
            'unusual_login' => sprintf(
                "Unusual login detected on your %s account from %s. " .
                "If this wasn't you, secure your account at %s",
                $this->config->get('app.name'),
                $alertData['location'],
                $this->config->get('app.url') . '/security'
            ),
            'password_changed' => sprintf(
                "Your %s password was changed. " .
                "If this wasn't you, contact support immediately at %s",
                $this->config->get('app.name'),
                $this->config->get('support.phone')
            ),
            'low_balance' => sprintf(
                "Your %s account balance is low (%s). " .
                "Top up at %s to avoid service interruption.",
                $this->config->get('app.name'),
                $alertData['balance'],
                $this->config->get('app.url') . '/billing'
            ),
            default => "Important alert from " . $this->config->get('app.name'),
        };

        return $this->send($phoneNumber, $message, 'account_alert');
    }

    private function send(string $phoneNumber, string $message, string $template): bool
    {
        if (!$this->config->get('sms.enabled', false)) {
            error_log("[SMS] SMS disabled, would have sent to {$phoneNumber}: {$message}");
            return false;
        }

        $maxLength = $this->config->get('sms.max_message_length', 160);

        if (strlen($message) > $maxLength) {
            $message = substr($message, 0, $maxLength - 3) . '...';
        }

        try {
            $result = $this->provider->send($phoneNumber, $message);

            error_log(sprintf(
                "[SMS] Sent %s to %s: %s",
                $template,
                $this->maskPhoneNumber($phoneNumber),
                $result->isSuccessful() ? 'success' : 'failed'
            ));

            return $result->isSuccessful();

        } catch (\Exception $e) {
            error_log(sprintf(
                "[SMS] Failed to send %s to %s: %s",
                $template,
                $this->maskPhoneNumber($phoneNumber),
                $e->getMessage()
            ));

            return false;
        }
    }

    private function getStatusMessage(string $status): string
    {
        return match ($status) {
            'processing' => 'is being processed',
            'shipped' => 'has been shipped',
            'out_for_delivery' => 'is out for delivery',
            'delivered' => 'has been delivered',
            'cancelled' => 'was cancelled',
            default => 'status updated',
        };
    }

    private function maskPhoneNumber(string $phoneNumber): string
    {
        return substr($phoneNumber, 0, 4) . '****' . substr($phoneNumber, -2);
    }
}
