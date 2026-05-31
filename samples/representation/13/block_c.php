<?php
declare(strict_types=1);

namespace App\Billing\DTO;

final class InvoiceLineItemDTO
{
    public function __construct(
        public readonly string $lineItemId,
        public readonly string $productId,
        public readonly string $productName,
        public readonly string $description,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly float $unitPrice,
        public readonly float $taxRate,
        public readonly float $taxAmount,
        public readonly float $totalAmount,
        public readonly string $currency,
        public readonly string $billingPeriod,
        public readonly \DateTimeImmutable $serviceDate,
        public readonly ?string $notes = null
    ) {}

    public static function fromOrderLineItem(
        \App\Order\Entity\OrderLineItem $lineItem,
        \DateTimeImmutable $serviceDate,
        string $billingPeriod
    ): self {
        $quantity = $lineItem->getQuantity();
        $unitPrice = $lineItem->getUnitPrice();
        $subtotal = $lineItem->getSubtotal();
        $taxRate = $lineItem->getTaxRate();
        $taxAmount = $subtotal * $taxRate;
        $totalAmount = $subtotal + $taxAmount;

        return new self(
            lineItemId: $lineItem->getId(),
            productId: $lineItem->getProductId(),
            productName: $lineItem->getProductName(),
            description: $lineItem->getDescription(),
            sku: $lineItem->getSku(),
            quantity: $quantity,
            unitPrice: $unitPrice,
            taxRate: $taxRate,
            taxAmount: $taxAmount,
            totalAmount: $totalAmount,
            currency: $lineItem->getCurrency(),
            billingPeriod: $billingPeriod,
            serviceDate: $serviceDate,
            notes: $lineItem->getNotes()
        );
    }

    public static function createSubscriptionLine(
        string $productId,
        string $productName,
        string $sku,
        int $quantity,
        float $unitPrice,
        float $taxRate,
        string $billingPeriod,
        \DateTimeImmutable $serviceDate
    ): self {
        $subtotal = $unitPrice * $quantity;
        $taxAmount = $subtotal * $taxRate;
        $totalAmount = $subtotal + $taxAmount;

        return new self(
            lineItemId: uniqid('li_'),
            productId: $productId,
            productName: $productName,
            description: "Subscription: {$productName}",
            sku: $sku,
            quantity: $quantity,
            unitPrice: $unitPrice,
            taxRate: $taxRate,
            taxAmount: $taxAmount,
            totalAmount: $totalAmount,
            currency: 'USD',
            billingPeriod: $billingPeriod,
            serviceDate: $serviceDate
        );
    }

    public function getSubtotal(): float
    {
        return $this->quantity * $this->unitPrice;
    }

    public function getFormattedUnitPrice(): string
    {
        return number_format($this->unitPrice, 2) . ' ' . $this->currency;
    }

    public function getFormattedTotalAmount(): string
    {
        return number_format($this->totalAmount, 2) . ' ' . $this->currency;
    }

    public function getFormattedTaxAmount(): string
    {
        return number_format($this->taxAmount, 2) . ' ' . $this->currency;
    }

    public function getTaxRatePercentage(): string
    {
        return number_format($this->taxRate * 100, 1) . '%';
    }

    public function toArray(): array
    {
        return [
            'line_item_id' => $this->lineItemId,
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'description' => $this->description,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'tax_rate' => $this->taxRate,
            'tax_amount' => $this->taxAmount,
            'total_amount' => $this->totalAmount,
            'currency' => $this->currency,
            'billing_period' => $this->billingPeriod,
            'service_date' => $this->serviceDate->format('Y-m-d'),
            'notes' => $this->notes
        ];
    }
}
