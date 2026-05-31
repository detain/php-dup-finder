<?php
declare(strict_types=1);

namespace App\Cart\DTO;

final class CartItemDTO
{
    public function __construct(
        public readonly string $productId,
        public readonly string $productName,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly float $unitPrice,
        public readonly float $totalPrice,
        public readonly string $currency,
        public readonly ?string $imageUrl,
        public readonly ?string $productSlug,
        public readonly bool $isAvailable,
        public readonly ?int $maxQuantity,
        public readonly array $selectedOptions = []
    ) {}

    public static function create(
        \App\Product\Entity\Product $product,
        int $quantity,
        array $selectedOptions = []
    ): self {
        $totalPrice = $product->getPrice() * $quantity;

        return new self(
            productId: $product->getId(),
            productName: $product->getName(),
            sku: $product->getSku(),
            quantity: $quantity,
            unitPrice: $product->getPrice(),
            totalPrice: $totalPrice,
            currency: $product->getCurrency(),
            imageUrl: $product->getPrimaryImage()?->getUrl(),
            productSlug: $product->getSlug(),
            isAvailable: $product->isInStock() && $product->getStockQuantity() >= $quantity,
            maxQuantity: $product->getStockQuantity(),
            selectedOptions: $selectedOptions
        );
    }

    public function getFormattedUnitPrice(): string
    {
        return number_format($this->unitPrice, 2) . ' ' . $this->currency;
    }

    public function getFormattedTotalPrice(): string
    {
        return number_format($this->totalPrice, 2) . ' ' . $this->currency;
    }

    public function canUpdateQuantity(int $newQuantity): bool
    {
        if ($newQuantity < 1) {
            return false;
        }

        if ($this->maxQuantity === null) {
            return true;
        }

        return $newQuantity <= $this->maxQuantity;
    }

    public function getUnitPriceInCents(): int
    {
        return (int) round($this->unitPrice * 100);
    }

    public function getTotalPriceInCents(): int
    {
        return (int) round($this->totalPrice * 100);
    }

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'total_price' => $this->totalPrice,
            'currency' => $this->currency,
            'image_url' => $this->imageUrl,
            'product_slug' => $this->productSlug,
            'is_available' => $this->isAvailable,
            'max_quantity' => $this->maxQuantity,
            'selected_options' => $this->selectedOptions
        ];
    }
}
