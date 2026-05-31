<?php

declare(strict_types=1);

namespace App\Hydrator;

use App\Entity\Order;
use App\Entity\OrderItem;

final class OrderHydrator
{
    public function hydrateFromArray(Order $order, array $data): Order
    {
        if (isset($data['status'])) {
            $order->setStatus($data['status']);
        }

        if (isset($data['shipping_address'])) {
            $order->setShippingAddress($data['shipping_address']);
        }

        if (isset($data['billing_address'])) {
            $order->setBillingAddress($data['billing_address']);
        }

        if (isset($data['notes'])) {
            $order->setNotes($data['notes']);
        }

        if (isset($data['payment_method'])) {
            $order->setPaymentMethod($data['payment_method']);
        }

        if (isset($data['shipping_method'])) {
            $order->setShippingMethod($data['shipping_method']);
        }

        return $order;
    }

    public function hydrateItemsFromArray(Order $order, array $itemsData): Order
    {
        $items = [];

        foreach ($itemsData as $itemData) {
            $item = new OrderItem(
                $itemData['product_id'],
                $itemData['quantity'],
                $itemData['unit_price']
            );

            if (isset($itemData['discount'])) {
                $item->setDiscount($itemData['discount']);
            }

            $items[] = $item;
        }

        $order->setItems($items);

        return $order;
    }

    public function extractToArray(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'number' => $order->getNumber(),
            'status' => $order->getStatus(),
            'customer_id' => $order->getCustomerId(),
            'shipping_address' => $order->getShippingAddress(),
            'billing_address' => $order->getBillingAddress(),
            'notes' => $order->getNotes(),
            'payment_method' => $order->getPaymentMethod(),
            'shipping_method' => $order->getShippingMethod(),
            'subtotal' => $order->getSubtotal(),
            'tax' => $order->getTax(),
            'shipping_cost' => $order->getShippingCost(),
            'total' => $order->getTotal(),
            'created_at' => $order->getCreatedAt()?->format('c'),
            'updated_at' => $order->getUpdatedAt()?->format('c'),
        ];
    }

    public function extractItemsToArray(Order $order): array
    {
        $items = [];

        foreach ($order->getItems() as $item) {
            $items[] = [
                'product_id' => $item->getProductId(),
                'product_name' => $item->getProductName(),
                'quantity' => $item->getQuantity(),
                'unit_price' => $item->getUnitPrice(),
                'discount' => $item->getDiscount(),
                'total' => $item->getTotal(),
            ];
        }

        return $items;
    }
}
