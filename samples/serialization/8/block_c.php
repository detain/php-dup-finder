<?php

declare(strict_types=1);

namespace App\Repository\Mapper;

class OrderResultMapper
{
    public function map(array $row): ?Order
    {
        if ($row === null || count($row) === 0) {
            return null;
        }

        return new Order(
            (string)$row['id'],
            (string)$row['user_id'],
            isset($row['items']) ? $this->unserializeItems($row['items']) : [],
            isset($row['total']) ? (float)$row['total'] : 0.0,
            isset($row['currency']) ? (string)$row['currency'] : 'USD',
            (string)$row['status'],
            isset($row['shipping_address']) && $row['shipping_address'] !== null ? (string)$row['shipping_address'] : null,
            isset($row['billing_address']) && $row['billing_address'] !== null ? (string)$row['billing_address'] : null,
            isset($row['created_at']) ? new \DateTimeImmutable($row['created_at']) : new \DateTimeImmutable(),
            isset($row['updated_at']) && $row['updated_at'] !== null ? new \DateTimeImmutable($row['updated_at']) : null,
            isset($row['shipped_at']) && $row['shipped_at'] !== null ? new \DateTimeImmutable($row['shipped_at']) : null
        );
    }

    public function mapMany(array $rows): array
    {
        return array_map(fn(array $row) => $this->map($row), $rows);
    }

    public function mapToArray(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'user_id' => $order->getUserId(),
            'items' => $this->serializeItems($order->getItems()),
            'total' => $order->getTotalAmount(),
            'currency' => $order->getCurrency(),
            'status' => $order->getStatus(),
            'shipping_address' => $order->getShippingAddress(),
            'billing_address' => $order->getBillingAddress(),
            'created_at' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $order->getUpdatedAt()?->format('Y-m-d H:i:s'),
            'shipped_at' => $order->getShippedAt()?->format('Y-m-d H:i:s')
        ];
    }

    private function unserializeItems(string $itemsJson): array
    {
        if (empty($itemsJson)) {
            return [];
        }

        $decoded = json_decode($itemsJson, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_map(function ($item) {
            return [
                'product_id' => (string)$item['product_id'],
                'quantity' => (int)$item['quantity'],
                'unit_price' => (float)$item['unit_price']
            ];
        }, $decoded);
    }

    private function serializeItems(array $items): string
    {
        return json_encode($items);
    }
}
