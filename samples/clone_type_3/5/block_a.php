<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\Serializer;
use App\Exception\ApiException;
use Psr\Log\LoggerInterface;

final class OrderApiController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly Serializer $serializer,
        private readonly LoggerInterface $logger,
    ) {}

    public function getOrder(int $id): array
    {
        $order = $this->orderRepository->find($id);

        if ($order === null) {
            throw new ApiException('Order not found', 404);
        }

        return $this->serializer->normalize($order, ['detail', 'customer', 'items']);
    }

    public function getOrdersByStatus(string $status, int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;

        $orders = $this->orderRepository->findByStatus($status, $limit, $offset);
        $total = $this->orderRepository->countByStatus($status);

        return [
            'data' => $this->serializer->normalize($orders, ['list']),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function createOrder(array $data): array
    {
        $errors = $this->validateOrderData($data);

        if (!empty($errors)) {
            throw new ApiException('Validation failed', 422, $errors);
        }

        $order = new Order(
            $data['customer_id'],
            $data['items'],
            $data['shipping_address'] ?? null,
            $data['billing_address'] ?? null
        );

        $this->orderRepository->save($order);

        $this->logger->info('Order created via API', [
            'order_id' => $order->getId(),
            'customer_id' => $data['customer_id'],
        ]);

        return $this->serializer->normalize($order, ['detail']);
    }

    public function updateOrder(int $id, array $data): array
    {
        $order = $this->orderRepository->find($id);

        if ($order === null) {
            throw new ApiException('Order not found', 404);
        }

        if (isset($data['status'])) {
            $order->setStatus($data['status']);
        }

        if (isset($data['shipping_address'])) {
            $order->setShippingAddress($data['shipping_address']);
        }

        if (isset($data['notes'])) {
            $order->setNotes($data['notes']);
        }

        $this->orderRepository->save($order);

        $this->logger->info('Order updated via API', [
            'order_id' => $order->getId(),
            'updates' => array_keys($data),
        ]);

        return $this->serializer->normalize($order, ['detail']);
    }

    public function cancelOrder(int $id, string $reason): array
    {
        $order = $this->orderRepository->find($id);

        if ($order === null) {
            throw new ApiException('Order not found', 404);
        }

        if (!$order->canBeCancelled()) {
            throw new ApiException('Order cannot be cancelled in current state', 422);
        }

        $order->cancel($reason);
        $this->orderRepository->save($order);

        $this->logger->info('Order cancelled via API', [
            'order_id' => $order->getId(),
            'reason' => $reason,
        ]);

        return $this->serializer->normalize($order, ['detail']);
    }

    private function validateOrderData(array $data): array
    {
        $errors = [];

        if (empty($data['customer_id'])) {
            $errors['customer_id'] = 'Customer ID is required';
        }

        if (empty($data['items']) || !is_array($data['items'])) {
            $errors['items'] = 'Items are required';
        } elseif (count($data['items']) === 0) {
            $errors['items'] = 'At least one item is required';
        }

        foreach ($data['items'] ?? [] as $index => $item) {
            if (empty($item['product_id'])) {
                $errors["items.{$index}.product_id"] = 'Product ID is required';
            }
            if (empty($item['quantity']) || $item['quantity'] < 1) {
                $errors["items.{$index}.quantity"] = 'Quantity must be at least 1';
            }
        }

        return $errors;
    }
}
