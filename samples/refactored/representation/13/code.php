<?php
declare(strict_types=1);

namespace App\Product\Model;

final class ProductModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $sku,
        public readonly float $price,
        public readonly ?float $originalPrice,
        public readonly string $currency,
        public readonly bool $inStock,
        public readonly int $stockQuantity,
        public readonly ?string $imageUrl,
        public readonly array $categories = [],
        public readonly array $tags = []
    ) {}

    public static function fromEntity(\App\Product\Entity\Product $product): self
    {
        return new self(
            id: $product->getId(),
            name: $product->getName(),
            slug: $product->getSlug(),
            sku: $product->getSku(),
            price: $product->getPrice(),
            originalPrice: $product->getOriginalPrice(),
            currency: $product->getCurrency(),
            inStock: $product->isInStock(),
            stockQuantity: $product->getStockQuantity(),
            imageUrl: $product->getPrimaryImage()?->getUrl(),
            categories: $product->getCategoryNames(),
            tags: $product->getTagNames()
        );
    }

    public function getDiscountPercent(): ?float
    {
        if ($this->originalPrice === null || $this->originalPrice <= $this->price) {
            return null;
        }

        return (($this->originalPrice - $this->price) / $this->originalPrice) * 100;
    }

    public function toSearchResult(float $relevanceScore = 0.0): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'price' => $this->price,
            'original_price' => $this->originalPrice,
            'currency' => $this->currency,
            'in_stock' => $this->inStock,
            'relevance_score' => $relevanceScore
        ];
    }

    public function toCartItem(int $quantity): array
    {
        return [
            'product_id' => $this->id,
            'product_name' => $this->name,
            'sku' => $this->sku,
            'quantity' => $quantity,
            'unit_price' => $this->price,
            'total_price' => $this->price * $quantity,
            'currency' => $this->currency,
            'is_available' => $this->inStock && $this->stockQuantity >= $quantity
        ];
    }
}
