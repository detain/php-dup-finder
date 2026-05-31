<?php
declare(strict_types=1);

namespace App\Order\DTO;

final class OrderDTO
{
    public function __construct(
        public readonly string $orderId,
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

    public static function fromEntity(\App\Order\Entity\Order $order): self
    {
        $lineItems = [];
        foreach ($order->getLineItems() as $item) {
            $lineItems[] = [
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'quantity' => $item->getQuantity(),
                'unit_price' => $item->getUnitPrice(),
                'total' => $item->getTotal()
            ];
        }

        return new self(
            orderId: $order->getId(),
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

    public function getFormattedTotal(): string
    {
        return number_format($this->totalAmount, 2) . ' ' . $this->currency;
    }

    public function getItemCount(): int
    {
        return array_reduce($this->lineItems, fn($sum, $item) => $sum + $item['quantity'], 0);
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, ['shipped', 'delivered'], true);
    }

    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'customer_id' => $this->customerId,
            'customer_email' => $this->customerEmail,
            'line_items' => $this->lineItems,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->taxAmount,
            'shipping_amount' => $this->shippingAmount,
            'total_amount' => $this->totalAmount,
            'currency' => $this->currency,
            'status' => $this->status,
            'created_at' => $this->createdAt->format('c'),
            'shipped_at' => $this->shippedAt?->format('c'),
            'delivered_at' => $this->deliveredAt?->format('c')
        ];
    }
}
