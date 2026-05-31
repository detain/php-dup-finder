<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\Order;
use App\Models\EmailMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;

final class OrderNotificationService
{
    private const EMAIL_ENABLED = true;
    private const EMAIL_FROM = 'noreply@example.com';
    private const EMAIL_FROM_NAME = 'Example Shop';
    private const TEMPLATE_ENGINE = 'twig';
    private const BATCH_SIZE = 50;
    private const RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY = 1000;
    private const TRACK_OPEN_RATE = true;
    private const TRACK_CLICK_RATE = true;
    private const ADD_UNSUBSCRIBE_LINK = true;
    private const UNSUBSCRIBE_URL = 'https://example.com/unsubscribe';
    private const MAX_RECIPIENTS = 100;
    private const TIMEOUT_SECONDS = 30;
    private const PRIORITY_HIGH = 1;
    private const PRIORITY_NORMAL = 3;
    private const PRIORITY_LOW = 5;

    private MailerInterface $mailer;
    private LoggerInterface $logger;

    public function __construct(MailerInterface $mailer, LoggerInterface $logger)
    {
        $this->mailer = $mailer;
        $this->logger = $logger;
    }

    public function sendOrderConfirmation(Order $order, User $user): bool
    {
        if (!self::EMAIL_ENABLED) {
            $this->logger->debug('Email notifications disabled, skipping order confirmation');
            return false;
        }

        $template = $this->loadTemplate('order_confirmation');
        $variables = $this->prepareOrderVariables($order);

        $message = new EmailMessage();
        $message->setTo($user->email);
        $message->setSubject('Order Confirmation - ' . $order->order_number);
        $message->setTemplate($template);
        $message->setVariables($variables);
        $message->setFrom(self::EMAIL_FROM, self::EMAIL_FROM_NAME);
        $message->setPriority(self::PRIORITY_NORMAL);

        if (self::ADD_UNSUBSCRIBE_LINK) {
            $message->addHeader('List-Unsubscribe', self::UNSUBSCRIBE_URL . '?email=' . urlencode($user->email));
        }

        if (self::TRACK_OPEN_RATE) {
            $message->addHeader('X-Track-Opens', 'true');
        }

        return $this->deliverEmail($message, [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'notification_type' => 'order_confirmation',
        ]);
    }

    public function sendOrderShippedNotification(Order $order, User $user): bool
    {
        if (!self::EMAIL_ENABLED) {
            return false;
        }

        $template = $this->loadTemplate('order_shipped');
        $variables = $this->prepareOrderVariables($order);
        $variables['tracking_number'] = $order->tracking_number;
        $variables['carrier'] = $order->carrier;
        $variables['estimated_delivery'] = $order->estimated_delivery;

        $message = new EmailMessage();
        $message->setTo($user->email);
        $message->setSubject('Your Order Has Shipped - ' . $order->order_number);
        $message->setTemplate($template);
        $message->setVariables($variables);
        $message->setFrom(self::EMAIL_FROM, self::EMAIL_FROM_NAME);
        $message->setPriority(self::PRIORITY_HIGH);

        if (self::ADD_UNSUBSCRIBE_LINK) {
            $message->addHeader('List-Unsubscribe', self::UNSUBSCRIBE_URL . '?email=' . urlencode($user->email));
        }

        if (self::TRACK_OPEN_RATE) {
            $message->addHeader('X-Track-Opens', 'true');
        }

        return $this->deliverEmail($message, [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'notification_type' => 'order_shipped',
        ]);
    }

    public function sendOrderDeliveredNotification(Order $order, User $user): bool
    {
        if (!self::EMAIL_ENABLED) {
            return false;
        }

        $template = $this->loadTemplate('order_delivered');
        $variables = $this->prepareOrderVariables($order);
        $variables['delivery_date'] = $order->delivered_at;

        $message = new EmailMessage();
        $message->setTo($user->email);
        $message->setSubject('Order Delivered - ' . $order->order_number);
        $message->setTemplate($template);
        $message->setVariables($variables);
        $message->setFrom(self::EMAIL_FROM, self::EMAIL_FROM_NAME);
        $message->setPriority(self::PRIORITY_NORMAL);

        if (self::ADD_UNSUBSCRIBE_LINK) {
            $message->addHeader('List-Unsubscribe', self::UNSUBSCRIBE_URL . '?email=' . urlencode($user->email));
        }

        return $this->deliverEmail($message, [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'notification_type' => 'order_delivered',
        ]);
    }

    public function sendOrderCancelledNotification(Order $order, User $user, string $reason): bool
    {
        if (!self::EMAIL_ENABLED) {
            return false;
        }

        $template = $this->loadTemplate('order_cancelled');
        $variables = $this->prepareOrderVariables($order);
        $variables['cancellation_reason'] = $reason;
        $variables['refund_amount'] = $order->total_amount;
        $variables['refund_processing_days'] = 5;

        $message = new EmailMessage();
        $message->setTo($user->email);
        $message->setSubject('Order Cancelled - ' . $order->order_number);
        $message->setTemplate($template);
        $message->setVariables($variables);
        $message->setFrom(self::EMAIL_FROM, self::EMAIL_FROM_NAME);
        $message->setPriority(self::PRIORITY_HIGH);

        if (self::ADD_UNSUBSCRIBE_LINK) {
            $message->addHeader('List-Unsubscribe', self::UNSUBSCRIBE_URL . '?email=' . urlencode($user->email));
        }

        return $this->deliverEmail($message, [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'notification_type' => 'order_cancelled',
        ]);
    }

    public function sendBulkOrderNotifications(array $orders): array
    {
        $results = [
            'sent' => 0,
            'failed' => 0,
            'failed_emails' => [],
        ];

        $batches = array_chunk($orders, self::BATCH_SIZE);

        foreach ($batches as $batch) {
            foreach ($batch as $orderData) {
                $order = $orderData['order'];
                $user = $orderData['user'];

                $success = $this->sendOrderConfirmation($order, $user);

                if ($success) {
                    $results['sent']++;
                } else {
                    $results['failed']++;
                    $results['failed_emails'][] = $user->email;
                }
            }

            if (count($batches) > 1) {
                usleep(self::RETRY_DELAY * 1000);
            }
        }

        $this->logger->info('Bulk order notifications completed', [
            'total' => count($orders),
            'sent' => $results['sent'],
            'failed' => $results['failed'],
            'batch_size' => self::BATCH_SIZE,
        ]);

        return $results;
    }

    private function deliverEmail(EmailMessage $message, array $context): bool
    {
        $attempts = 0;

        while ($attempts < self::RETRY_ATTEMPTS) {
            try {
                $this->mailer->send($message);

                $this->logger->info('Email delivered successfully', [
                    'to' => $message->getTo(),
                    'subject' => $message->getSubject(),
                    'attempts' => $attempts + 1,
                    'timeout' => self::TIMEOUT_SECONDS,
                    'context' => $context,
                ]);

                return true;
            } catch (\Exception $e) {
                $attempts++;

                $this->logger->warning('Email delivery failed', [
                    'to' => $message->getTo(),
                    'attempt' => $attempts,
                    'max_retries' => self::RETRY_ATTEMPTS,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::RETRY_DELAY,
                ]);

                if ($attempts >= self::RETRY_ATTEMPTS) {
                    return false;
                }

                usleep(self::RETRY_DELAY * 1000 * $attempts);
            }
        }

        return false;
    }

    private function loadTemplate(string $name): string
    {
        return file_get_contents(__DIR__ . '/templates/' . $name . '.html');
    }

    private function prepareOrderVariables(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'order_date' => $order->created_at->format('Y-m-d H:i:s'),
            'items' => $order->items,
            'subtotal' => $order->subtotal,
            'tax' => $order->tax,
            'shipping_cost' => $order->shipping_cost,
            'total' => $order->total_amount,
            'currency' => $order->currency,
            'shipping_address' => $order->shipping_address,
            'billing_address' => $order->billing_address,
            'company_name' => self::EMAIL_FROM_NAME,
            'support_email' => 'support@example.com',
        ];
    }

    public function isEmailEnabled(): bool
    {
        return self::EMAIL_ENABLED;
    }

    public function getMaxRecipients(): int
    {
        return self::MAX_RECIPIENTS;
    }
}
