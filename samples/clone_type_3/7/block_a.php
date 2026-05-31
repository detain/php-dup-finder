<?php

declare(strict_types=1);

namespace App\Transform;

use App\Entity\Order;
use App\Entity\OrderItem;

final class OrderMapper
{
    public function toArray(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'number' => $order->getNumber(),
            'status' => $order->getStatus(),
            'customer' => [
                'id' => $order->getCustomer()->getId(),
                'name' => $order->getCustomer()->getName(),
                'email' => $order->getCustomer()->getEmail(),
            ],
            'items' => array_map(
                fn(OrderItem $item) => [
                    'product_id' => $item->getProductId(),
                    'product_name' => $item->getProductName(),
                    'quantity' => $item->getQuantity(),
                    'unit_price' => $item->getUnitPrice(),
                    'total' => $item->getTotal(),
                ],
                $order->getItems()
            ),
            'subtotal' => $order->getSubtotal(),
            'tax' => $order->getTax(),
            'shipping' => $order->getShippingCost(),
            'total' => $order->getTotal(),
            'created_at' => $order->getCreatedAt()->format('c'),
            'updated_at' => $order->getUpdatedAt()->format('c'),
        ];
    }

    public function toSummaryArray(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'number' => $order->getNumber(),
            'status' => $order->getStatus(),
            'customer_name' => $order->getCustomer()->getName(),
            'item_count' => count($order->getItems()),
            'total' => $order->getTotal(),
            'created_at' => $order->getCreatedAt()->format('Y-m-d'),
        ];
    }

    public function toCsvRow(Order $order): array
    {
        return [
            $order->getNumber(),
            $order->getCustomer()->getName(),
            $order->getCustomer()->getEmail(),
            count($order->getItems()),
            number_format($order->getTotal(), 2),
            $order->getStatus(),
            $order->getCreatedAt()->format('Y-m-d H:i:s'),
        ];
    }

    public function toFlatArray(Order $order): array
    {
        $flat = [
            'order_id' => $order->getId(),
            'order_number' => $order->getNumber(),
            'order_status' => $order->getStatus(),
            'order_subtotal' => $order->getSubtotal(),
            'order_tax' => $order->getTax(),
            'order_shipping' => $order->getShippingCost(),
            'order_total' => $order->getTotal(),
            'customer_id' => $order->getCustomer()->getId(),
            'customer_name' => $order->getCustomer()->getName(),
            'customer_email' => $order->getCustomer()->getEmail(),
        ];

        foreach ($order->getItems() as $index => $item) {
            $flat["item_{$index}_product_id"] = $item->getProductId();
            $flat["item_{$index}_product_name"] = $item->getProductName();
            $flat["item_{$index}_quantity"] = $item->getQuantity();
            $flat["item_{$index}_unit_price"] = $item->getUnitPrice();
            $flat["item_{$index}_total"] = $item->getTotal();
        }

        return $flat;
    }
}
