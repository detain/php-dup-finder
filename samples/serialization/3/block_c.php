<?php

declare(strict_types=1);

namespace App\Dto;

class OrderArrayConverter
{
    public function fromEntity(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'user_id' => $order->getUserId(),
            'items' => $this->formatItems($order->getItems()),
            'total_amount' => $order->getTotalAmount(),
            'total_currency' => $order->getCurrency(),
            'status' => $order->getStatus(),
            'shipping_address' => $order->getShippingAddress(),
            'billing_address' => $order->getBillingAddress(),
            'created_at' => $this->formatDateTime($order->getCreatedAt()),
            'updated_at' => $this->formatNullableDateTime($order->getUpdatedAt()),
            'shipped_at' => $this->formatNullableDateTime($order->getShippedAt())
        ];
    }

    public function toEntity(array $data): Order
    {
        return new Order(
            $data['id'],
            $data['user_id'],
            $this->parseItems($data['items']),
            $data['total_amount'],
            $data['total_currency'],
            $data['status'],
            $data['shipping_address'] ?? null,
            $data['billing_address'] ?? null,
            $this->parseDateTime($data['created_at']),
            isset($data['updated_at']) ? $this->parseDateTime($data['updated_at']) : null,
            isset($data['shipped_at']) ? $this->parseDateTime($data['shipped_at']) : null
        );
    }

    public function fromEntityCompact(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'user_id' => $order->getUserId(),
            'total_amount' => $order->getTotalAmount(),
            'total_currency' => $order->getCurrency(),
            'status' => $order->getStatus(),
            'created_at' => $this->formatDateTime($order->getCreatedAt())
        ];
    }

    public function fromEntitySummary(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'total_amount' => $order->getTotalAmount(),
            'currency' => $order->getCurrency(),
            'status' => $order->getStatus(),
            'item_count' => count($order->getItems())
        ];
    }

    public function fromEntities(array $orders): array
    {
        return array_map(fn(Order $order) => $this->fromEntity($order), $orders);
    }

    private function formatItems(array $items): array
    {
        return array_map(fn($item) => [
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price']
        ], $items);
    }

    private function parseItems(array $items): array
    {
        return array_map(fn($item) => [
            'product_id' => $item['product_id'],
            'quantity' => (int)$item['quantity'],
            'unit_price' => (float)$item['unit_price']
        ], $items);
    }

    private function formatDateTime(\DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }

    private function formatNullableDateTime(?\DateTimeInterface $dateTime): ?string
    {
        return $dateTime?->format('Y-m-d H:i:s');
    }

    private function parseDateTime(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }
}
