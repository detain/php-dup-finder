<?php
declare(strict_types=1);

namespace RetailPlatform\API\Formatter;

use Psr\Log\LoggerInterface;

final class OrderResponseFormatter
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function format(array $orderData): array
    {
        $this->logger->debug('Formatting order response', ['order_id' => $orderData['id'] ?? 'unknown']);

        if (!isset($orderData['id'])) {
            throw new \InvalidArgumentException('Order ID is required');
        }

        if (!isset($orderData['order_number']) || empty(trim($orderData['order_number']))) {
            throw new \InvalidArgumentException('Order number is required');
        }

        if (!isset($orderData['customer_id'])) {
            throw new \InvalidArgumentException('Customer ID is required');
        }

        if (!is_numeric($orderData['total_amount'] ?? null)) {
            throw new \InvalidArgumentException('Total amount must be numeric');
        }

        if (($orderData['total_amount'] ?? 0) < 0) {
            throw new \InvalidArgumentException('Total amount cannot be negative');
        }

        if (isset($orderData['item_count']) && !is_int($orderData['item_count'])) {
            throw new \InvalidArgumentException('Item count must be an integer');
        }

        if (isset($orderData['item_count']) && $orderData['item_count'] < 0) {
            throw new \InvalidArgumentException('Item count cannot be negative');
        }

        if (isset($orderData['items']) && !is_array($orderData['items'])) {
            throw new \InvalidArgumentException('Items must be an array');
        }

        if (isset($orderData['shipping_address']) && !is_array($orderData['shipping_address'])) {
            throw new \InvalidArgumentException('Shipping address must be an array');
        }

        if (isset($orderData['billing_address']) && !is_array($orderData['billing_address'])) {
            throw new \InvalidArgumentException('Billing address must be an array');
        }

        return $this->buildResponse($orderData);
    }

    public function formatList(array $orders): array
    {
        return array_map(fn($order) => $this->format($order), $orders);
    }

    private function buildResponse(array $orderData): array
    {
        return [
            'id' => (int)$orderData['id'],
            'order_number' => trim($orderData['order_number']),
            'customer_id' => (int)$orderData['customer_id'],
            'status' => $this->normalizeStatus($orderData['status'] ?? 'pending'),
            'total_amount' => (float)$orderData['total_amount'],
            'currency' => $orderData['currency'] ?? 'USD',
            'item_count' => isset($orderData['item_count'])
                ? (int)$orderData['item_count']
                : count($orderData['items'] ?? []),
            'items' => $this->formatItems($orderData['items'] ?? []),
            'shipping_address' => $this->formatAddress($orderData['shipping_address'] ?? []),
            'billing_address' => $this->formatAddress($orderData['billing_address'] ?? []),
            'payment_method' => $orderData['payment_method'] ?? null,
            'metadata' => $this->formatMetadata($orderData['metadata'] ?? []),
            'formatted_total' => $this->formatPrice($orderData['total_amount'], $orderData['currency'] ?? 'USD'),
            'is_paid' => ($orderData['status'] ?? '') === 'paid',
            'created_at' => $orderData['created_at'] ?? null,
            'updated_at' => $orderData['updated_at'] ?? null,
        ];
    }

    private function formatItems(array $items): array
    {
        return array_map(function ($item) {
            return [
                'product_id' => (int)($item['product_id'] ?? 0),
                'sku' => trim($item['sku'] ?? ''),
                'name' => trim($item['name'] ?? ''),
                'quantity' => (int)($item['quantity'] ?? 1),
                'unit_price' => (float)($item['unit_price'] ?? 0),
                'subtotal' => (float)(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0)),
            ];
        }, $items);
    }

    private function formatAddress(array $address): array
    {
        return [
            'street' => trim($address['street'] ?? ''),
            'city' => trim($address['city'] ?? ''),
            'state' => trim($address['state'] ?? ''),
            'postal_code' => trim($address['postal_code'] ?? ''),
            'country' => trim($address['country'] ?? ''),
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $validStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
        $normalized = strtolower(trim($status));
        return in_array($normalized, $validStatuses) ? $normalized : 'pending';
    }

    private function formatMetadata(array $metadata): array
    {
        $formatted = [];
        foreach ($metadata as $key => $value) {
            $formatted[$key] = is_scalar($value) ? (string)$value : json_encode($value);
        }
        return $formatted;
    }

    private function formatPrice(float $price, string $currency): string
    {
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format($price, 2);
    }
}
