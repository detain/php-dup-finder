<?php
declare(strict_types=1);

namespace App\Order\Model;

use App\Order\Entity\Order;

final class OrderModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $orderNumber,
        public readonly string $customerId,
        public readonly string $customerEmail,
        public readonly array $lineItems,
        public readonly float $subtotal,
        public readonly float $taxAmount,
        public readonly float $shippingAmount,
        public readonly float $totalAmount,
        public readonly string $currency,
        public readonly string $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $shippedAt,
        public readonly ?\DateTimeImmutable $deliveredAt
    ) {}

    public static function fromEntity(Order $order): self
    {
        $lineItems = array_map(
            fn($item) => new OrderLineItemModel(
                $item->getSku(),
                $item->getName(),
                $item->getQuantity(),
                $item->getUnitPrice(),
                $item->getTotal()
            ),
            $order->getLineItems()
        );

        return new self(
            id: $order->getId(),
            orderNumber: $order->getOrderNumber(),
            customerId: $order->getCustomerId(),
            customerEmail: $order->getCustomer()->getEmail(),
            lineItems: $lineItems,
            subtotal: $order->getSubtotal(),
            taxAmount: $order->getTaxAmount(),
            shippingAmount: $order->getShippingAmount(),
            totalAmount: $order->getTotalAmount(),
            currency: $order->getCurrency(),
            status: $order->getStatus(),
            createdAt: $order->getCreatedAt(),
            shippedAt: $order->getShippedAt(),
            deliveredAt: $order->getDeliveredAt()
        );
    }

    public function getItemCount(): int
    {
        return array_reduce(
            $this->lineItems,
            fn($sum, $item) => $sum + $item->quantity,
            0
        );
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, ['shipped', 'delivered'], true);
    }
}

final class OrderLineItemModel
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly int $quantity,
        public readonly float $unitPrice,
        public readonly float $total
    ) {}
}
