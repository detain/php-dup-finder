<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\EmailService;
use App\Service\SmsService;
use Psr\Log\LoggerInterface;

final class OrderNotificationService
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly EmailService $emailService,
        private readonly SmsService $smsService,
        private readonly LoggerInterface $logger,
    ) {}

    public function notifyOrderCreated(Order $order): void
    {
        $customer = $order->getCustomer();
        $template = 'order_created';

        $emailResult = $this->emailService->send(
            $customer->getEmail(),
            $this->renderEmailTemplate($template, $order),
            'Order Confirmation - #' . $order->getNumber()
        );

        if (!$emailResult) {
            $this->logger->error('Failed to send order created email', [
                'order_id' => $order->getId(),
                'customer_email' => $customer->getEmail(),
            ]);
        }

        if ($customer->getPhone()) {
            $smsResult = $this->smsService->send(
                $customer->getPhone(),
                $this->renderSmsTemplate($template, $order)
            );

            if (!$smsResult) {
                $this->logger->error('Failed to send order created SMS', [
                    'order_id' => $order->getId(),
                    'customer_phone' => $customer->getPhone(),
                ]);
            }
        }

        $this->logger->info('Order created notifications sent', [
            'order_id' => $order->getId(),
            'customer_id' => $customer->getId(),
        ]);
    }

    public function notifyOrderShipped(Order $order): void
    {
        $customer = $order->getCustomer();
        $template = 'order_shipped';

        $emailResult = $this->emailService->send(
            $customer->getEmail(),
            $this->renderEmailTemplate($template, $order),
            'Your Order Has Shipped - #' . $order->getNumber()
        );

        if (!$emailResult) {
            $this->logger->error('Failed to send order shipped email', [
                'order_id' => $order->getId(),
                'customer_email' => $customer->getEmail(),
            ]);
        }

        if ($customer->getPhone()) {
            $smsResult = $this->smsService->send(
                $customer->getPhone(),
                $this->renderSmsTemplate($template, $order)
            );

            if (!$smsResult) {
                $this->logger->error('Failed to send order shipped SMS', [
                    'order_id' => $order->getId(),
                    'customer_phone' => $customer->getPhone(),
                ]);
            }
        }

        $this->logger->info('Order shipped notifications sent', [
            'order_id' => $order->getId(),
            'customer_id' => $customer->getId(),
        ]);
    }

    public function notifyOrderDelivered(Order $order): void
    {
        $customer = $order->getCustomer();
        $template = 'order_delivered';

        $emailResult = $this->emailService->send(
            $customer->getEmail(),
            $this->renderEmailTemplate($template, $order),
            'Order Delivered - #' . $order->getNumber()
        );

        if (!$emailResult) {
            $this->logger->error('Failed to send order delivered email', [
                'order_id' => $order->getId(),
                'customer_email' => $customer->getEmail(),
            ]);
        }

        if ($customer->getPhone()) {
            $smsResult = $this->smsService->send(
                $customer->getPhone(),
                $this->renderSmsTemplate($template, $order)
            );

            if (!$smsResult) {
                $this->logger->error('Failed to send order delivered SMS', [
                    'order_id' => $order->getId(),
                    'customer_phone' => $customer->getPhone(),
                ]);
            }
        }

        $this->logger->info('Order delivered notifications sent', [
            'order_id' => $order->getId(),
            'customer_id' => $customer->getId(),
        ]);
    }

    public function notifyOrderCancelled(Order $order): void
    {
        $customer = $order->getCustomer();
        $template = 'order_cancelled';

        $emailResult = $this->emailService->send(
            $customer->getEmail(),
            $this->renderEmailTemplate($template, $order),
            'Order Cancelled - #' . $order->getNumber()
        );

        if (!$emailResult) {
            $this->logger->error('Failed to send order cancelled email', [
                'order_id' => $order->getId(),
                'customer_email' => $customer->getEmail(),
            ]);
        }

        if ($customer->getPhone()) {
            $smsResult = $this->smsService->send(
                $customer->getPhone(),
                $this->renderSmsTemplate($template, $order)
            );

            if (!$smsResult) {
                $this->logger->error('Failed to send order cancelled SMS', [
                    'order_id' => $order->getId(),
                    'customer_phone' => $customer->getPhone(),
                ]);
            }
        }

        $this->logger->info('Order cancelled notifications sent', [
            'order_id' => $order->getId(),
            'customer_id' => $customer->getId(),
        ]);
    }

    private function renderEmailTemplate(string $template, Order $order): string
    {
        return str_replace(
            ['{{order_number}}', '{{customer_name}}', '{{order_total}}'],
            [$order->getNumber(), $order->getCustomer()->getName(), $order->getTotal()],
            file_get_contents(__DIR__ . '/templates/' . $template . '.html')
        );
    }

    private function renderSmsTemplate(string $template, Order $order): string
    {
        return sprintf(
            'Order %s: %s. Total: $%.2f',
            $order->getNumber(),
            $template === 'order_cancelled' ? 'has been cancelled' : 'confirmed',
            $order->getTotal()
        );
    }
}
