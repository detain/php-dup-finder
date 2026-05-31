<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Infrastructure\Config\ConfigService;

/**
 * Push notification service.
 * The ConfigService is manually injected here, duplicated from
 * EmailService, SmsService, and other notification services.
 */
class PushNotificationService
{
    private ConfigService $config;
    private PushProviderInterface $provider;

    public function __construct(ConfigService $config, PushProviderInterface $provider)
    {
        $this->config = $config;
        $this->provider = $provider;
    }

    public function sendOrderUpdate(string $userId, string $deviceToken, array $orderData): bool
    {
        $title = 'Order Update';
        $body = sprintf(
            'Order %s: %s',
            $orderData['order_number'],
            $this->getOrderStatusMessage($orderData['status'])
        );

        $data = [
            'type' => 'order_update',
            'order_id' => $orderData['order_id'],
            'click_action' => $this->config->get('app.url') . '/orders/' . $orderData['order_id'],
        ];

        return $this->send($userId, $deviceToken, $title, $body, $data);
    }

    public function sendPromotion(string $userId, string $deviceToken, array $promoData): bool
    {
        $title = $promoData['title'] ?? 'Special Offer!';
        $body = $promoData['body'] ?? 'Check out our latest deals!';

        $data = [
            'type' => 'promotion',
            'promo_id' => $promoData['promo_id'] ?? null,
            'click_action' => $promoData['landing_url'] ?? $this->config->get('app.url') . '/promotions',
        ];

        return $this->send($userId, $deviceToken, $title, $body, $data);
    }

    public function sendPriceAlert(string $userId, string $deviceToken, array $priceAlert): bool
    {
        $title = 'Price Drop!';
        $body = sprintf(
            '%s is now %s (was %s). Limited time offer!',
            $priceAlert['product_name'],
            $priceAlert['new_price'],
            $priceAlert['old_price']
        );

        $data = [
            'type' => 'price_alert',
            'product_id' => $priceAlert['product_id'],
            'click_action' => $this->config->get('app.url') . '/product/' . $priceAlert['product_id'],
        ];

        return $this->send($userId, $deviceToken, $title, $body, $data);
    }

    public function sendBackInStock(string $userId, string $deviceToken, array $backInStockData): bool
    {
        $title = 'Back in Stock!';
        $body = sprintf(
            '%s is now available again. Order now before it sells out!',
            $backInStockData['product_name']
        );

        $data = [
            'type' => 'back_in_stock',
            'product_id' => $backInStockData['product_id'],
            'click_action' => $this->config->get('app.url') . '/product/' . $backInStockData['product_id'],
        ];

        return $this->send($userId, $deviceToken, $title, $body, $data);
    }

    public function sendReminder(string $userId, string $deviceToken, array $reminderData): bool
    {
        $title = $reminderData['title'] ?? 'Reminder';
        $body = $reminderData['body'] ?? 'You have a pending action';

        $data = [
            'type' => 'reminder',
            'reminder_id' => $reminderData['reminder_id'] ?? null,
            'click_action' => $reminderData['action_url'] ?? $this->config->get('app.url'),
        ];

        return $this->send($userId, $deviceToken, $title, $body, $data);
    }

    private function send(
        string $userId,
        string $deviceToken,
        string $title,
        string $body,
        array $data = []
    ): bool {

        if (!$this->config->get('push.enabled', false)) {
            error_log("[Push] Push disabled, would have sent to user {$userId}: {$title}");
            return false;
        }

        $notification = [
            'user_id' => $userId,
            'device_token' => $deviceToken,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'badge' => $this->config->get('push.badge', 1),
            'sound' => $this->config->get('push.sound', 'default'),
        ];

        try {
            $result = $this->provider->send($notification);

            error_log(sprintf(
                "[Push] Sent to user %s: %s (%s)",
                $userId,
                $title,
                $result->isSuccessful() ? 'success' : 'failed'
            ));

            return $result->isSuccessful();

        } catch (\Exception $e) {
            error_log(sprintf(
                "[Push] Failed to send to user %s: %s",
                $userId,
                $e->getMessage()
            ));

            return false;
        }
    }

    private function getOrderStatusMessage(string $status): string
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
}
