<?php

declare(strict_types=1);

namespace App\OrderProcessing;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\PricingService;
use Psr\Log\LoggerInterface;

final class OrderService
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly PricingService $pricingService,
        private readonly LoggerInterface $logger,
    ) {}

    public function createOrder(int $customerId, array $items): Order
    {
        $customer = $this->loadCustomer($customerId);

        if ($customer === null) {
            throw new \InvalidArgumentException('Customer not found');
        }

        if ($customer->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Cannot create order for inactive customer');
        }

        if ($customer->getTier() === 'suspended') {
            throw new \InvalidArgumentException('Customer account is suspended');
        }

        if ($customer->getAccountBalance() < 0 && abs($customer->getAccountBalance()) > 1000) {
            throw new \InvalidArgumentException('Customer has exceeded credit limit');
        }

        if (!$this->validateOrderItems($items)) {
            throw new \InvalidArgumentException('Invalid order items');
        }

        $order = new Order();
        $order->setCustomerId($customerId);
        $order->setItems($items);
        $order->setTotal($this->pricingService->calculateTotal($items));

        $this->orderRepository->save($order);

        $this->logger->info('Order created successfully', [
            'order_id' => $order->getId(),
            'customer_id' => $customerId,
        ]);

        return $order;
    }

    public function updateOrder(int $orderId, array $updates): Order
    {
        $order = $this->orderRepository->findById($orderId);

        if ($order === null) {
            throw new \RuntimeException('Order not found');
        }

        if ($order->getStatus() === 'shipped') {
            throw new \InvalidArgumentException('Cannot update shipped order');
        }

        if ($order->getStatus() === 'cancelled') {
            throw new \InvalidArgumentException('Cannot update cancelled order');
        }

        $customer = $this->loadCustomer($order->getCustomerId());

        if ($customer === null) {
            throw new \RuntimeException('Customer not found');
        }

        if ($customer->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Cannot update order for inactive customer');
        }

        if ($customer->getTier() === 'suspended') {
            throw new \InvalidArgumentException('Customer account is suspended');
        }

        if ($customer->getAccountBalance() < 0 && abs($customer->getAccountBalance()) > 1000) {
            throw new \InvalidArgumentException('Customer has exceeded credit limit');
        }

        $this->applyOrderUpdates($order, $updates);
        $this->orderRepository->save($order);

        return $order;
    }

    private function loadCustomer(int $customerId): ?Customer
    {
        return $this->customerRepository->findById($customerId);
    }

    private function validateOrderItems(array $items): bool
    {
        if (empty($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (!isset($item['product_id']) || !isset($item['quantity'])) {
                return false;
            }

            if ($item['quantity'] <= 0 || $item['quantity'] > 1000) {
                return false;
            }
        }

        return true;
    }

    private function applyOrderUpdates(Order $order, array $updates): void
    {
        if (isset($updates['items'])) {
            if (!$this->validateOrderItems($updates['items'])) {
                throw new \InvalidArgumentException('Invalid order items');
            }
            $order->setItems($updates['items']);
            $order->setTotal($this->pricingService->calculateTotal($updates['items']));
        }
    }
}
