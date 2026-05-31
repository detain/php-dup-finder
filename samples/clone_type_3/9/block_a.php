<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Service\MailService;
use App\Repository\OrderRepository;
use Psr\Log\LoggerInterface;

final class SendOrderConfirmationJob
{
    public function __construct(
        private readonly MailService $mailService,
        private readonly OrderRepository $orderRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(int $orderId): bool
    {
        $order = $this->orderRepository->find($orderId);

        if ($order === null) {
            $this->logger->error('Order not found for confirmation email', [
                'order_id' => $orderId,
            ]);
            return false;
        }

        if ($order->getStatus() !== 'pending') {
            $this->logger->info('Order already processed, skipping confirmation', [
                'order_id' => $orderId,
                'status' => $order->getStatus(),
            ]);
            return true;
        }

        try {
            $result = $this->mailService->send(
                $order->getCustomer()->getEmail(),
                'order_confirmation',
                [
                    'order_number' => $order->getNumber(),
                    'customer_name' => $order->getCustomer()->getName(),
                    'order_total' => $order->getTotal(),
                    'items' => $order->getItems(),
                ]
            );

            if ($result) {
                $this->logger->info('Order confirmation email sent', [
                    'order_id' => $orderId,
                    'customer_email' => $order->getCustomer()->getEmail(),
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send order confirmation email', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
