<?php
declare(strict_types=1);

namespace Notifications\Sms;

use Psr\Log\LoggerInterface;
use Twilio\Rest\Client as TwilioClient;
use Symfony\Component\HttpFoundation\Request;

final class SmsNotificationHandler
{
    public function __construct(
        private readonly TwilioClient $twilio,
        private readonly LoggerInterface $logger,
        private readonly array $config,
        private readonly SmsLogRepository $smsLogRepo,
        private readonly RateLimiter $rateLimiter
    ) {}

    public function sendOrderUpdate(Order $order): SmsResult
    {
        $customer = $order->getCustomer();
        $phone = $customer->getPhone();

        if (empty($phone)) {
            return SmsResult::failure('No phone number on file');
        }

        $this->logger->info('Sending order update SMS', [
            'order_id' => $order->getId(),
            'phone' => substr($phone, 0, 4) . '****'
        ]);

        // Rate limit check
        $rateLimit = $this->config['notifications']['sms']['rate_limit_per_hour'] ?? 10;
        if (!$this->rateLimiter->allowSms($customer->getId(), $rateLimit)) {
            return SmsResult::failure('Rate limit exceeded');
        }

        try {
            $message = $this->buildOrderUpdateMessage($order);

            $this->twilio->messages->create(
                $phone,
                [
                    'from' => $this->config['notifications']['sms']['twilio_from_number'],
                    'body' => $message
                ]
            );

            // Log SMS
            $this->logSms($customer->getId(), $phone, 'order_update', $order->getId());

            return SmsResult::success();

        } catch (\Exception $e) {
            $this->logger->error('Failed to send order update SMS', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);
            return SmsResult::failure($e->getMessage());
        }
    }

    public function sendTwoFactorCode(User $user, string $code): SmsResult
    {
        $phone = $user->getPhone();

        if (empty($phone)) {
            return SmsResult::failure('No phone number on file');
        }

        $this->logger->info('Sending 2FA code SMS', [
            'user_id' => $user->getId()
        ]);

        try {
            $template = $this->config['notifications']['sms']['templates']['two_factor']
                ?? 'Your verification code is: {{code}}. Valid for 5 minutes.';
            $message = str_replace('{{code}}', $code, $template);

            $this->twilio->messages->create(
                $phone,
                [
                    'from' => $this->config['notifications']['sms']['twilio_from_number'],
                    'body' => $message
                ]
            );

            $this->logSms($user->getId(), $phone, 'two_factor', null);

            return SmsResult::success();

        } catch (\Exception $e) {
            $this->logger->error('Failed to send 2FA SMS', [
                'user_id' => $user->getId()
            ]);
            return SmsResult::failure($e->getMessage());
        }
    }

    public function sendShippingNotification(Order $order, string $trackingNumber): SmsResult
    {
        $customer = $order->getCustomer();
        $phone = $customer->getPhone();

        if (empty($phone)) {
            return SmsResult::failure('No phone number on file');
        }

        $template = $this->config['notifications']['sms']['templates']['shipping']
            ?? 'Your order #{{order_id}} has shipped! Track: {{tracking_url}}';
        $trackingUrl = $this->config['shipping']['tracking_url_template']
            ?? 'https://track.example.com/{tracking_number}';

        $message = str_replace(
            ['{{order_id}}', '{{tracking_number}}', '{{tracking_url}}'],
            [$order->getId(), $trackingNumber, str_replace('{tracking_number}', $trackingNumber, $trackingUrl)],
            $template
        );

        try {
            $this->twilio->messages->create(
                $phone,
                [
                    'from' => $this->config['notifications']['sms']['twilio_from_number'],
                    'body' => $message
                ]
            );

            $this->logSms($customer->getId(), $phone, 'shipping_notification', $order->getId());

            return SmsResult::success();

        } catch (\Exception $e) {
            $this->logger->error('Failed to send shipping SMS', [
                'order_id' => $order->getId()
            ]);
            return SmsResult::failure($e->getMessage());
        }
    }

    private function buildOrderUpdateMessage(Order $order): string
    {
        return sprintf(
            'Order #%d is now %s. Thank you for shopping with us!',
            $order->getId(),
            $order->getStatus()
        );
    }

    private function logSms(int $userId, string $phone, string $type, ?int $referenceId): void
    {
        $log = new SmsLog();
        $log->setUserId($userId);
        $log->setPhone($phone);
        $log->setType($type);
        $log->setReferenceId($referenceId);
        $log->setSentAt(new \DateTimeImmutable());

        $this->smsLogRepo->save($log);
    }
}
