<?php
declare(strict_types=1);

namespace App\Product\Search;

final class ProductSearchResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $imageUrl,
        public readonly ?string $thumbnailUrl,
        public readonly float $price,
        public readonly ?float $originalPrice,
        public readonly ?float $discountPercent,
        public readonly string $currency,
        public readonly bool $inStock,
        public readonly int $stockQuantity,
        public readonly float $rating,
        public readonly int $reviewCount,
        public readonly array $categories,
        public readonly array $tags,
        public readonly float $relevanceScore,
        public readonly bool $isFeatured,
        public readonly string $searchHighlight = ''
    ) {}

    public static function fromEntity(\App\Product\Entity\Product $product, float $relevanceScore = 0.0): self
    {
        return new self(
            id: $product->getId(),
            name: $product->getName(),
            slug: $product->getSlug(),
            imageUrl: $product->getPrimaryImage()?->getUrl(),
            thumbnailUrl: $product->getPrimaryImage()?->getThumbnailUrl(),
            price: $product->getPrice(),
            originalPrice: $product->getOriginalPrice(),
            discountPercent: $product->getDiscountPercent(),
            currency: $product->getCurrency(),
            inStock: $product->isInStock(),
            stockQuantity: $product->getStockQuantity(),
            rating: $product->getAverageRating(),
            reviewCount: $product->getReviewCount(),
            categories: $product->getCategoryNames(),
            tags: $product->getTagNames(),
            relevanceScore: $relevanceScore,
            isFeatured: $product->isFeatured(),
            searchHighlight: ''
        );
    }

    public function hasDiscount(): bool
    {
        return $this->discountPercent !== null && $this->discountPercent > 0;
    }

    public function getFormattedPrice(): string
    {
        return number_format($this->price, 2) . ' ' . $this->currency;
    }

    public function getStockStatus(): string
    {
        if (!$this->inStock) {
            return 'out_of_stock';
        }

        if ($this->stockQuantity < 5) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    public function matchesQuery(string $query): bool
    {
        $query = strtolower($query);

        return str_contains(strtolower($this->name), $query)
            || str_contains(strtolower($this->slug), $query);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'image_url' => $this->imageUrl,
            'thumbnail_url' => $this->thumbnailUrl,
            'price' => $this->price,
            'original_price' => $this->originalPrice,
            'discount_percent' => $this->discountPercent,
            'currency' => $this->currency,
            'in_stock' => $this->inStock,
            'stock_quantity' => $this->stockQuantity,
            'rating' => $this->rating,
            'review_count' => $this->reviewCount,
            'categories' => $this->categories,
            'tags' => $this->tags,
            'relevance_score' => $this->relevanceScore,
            'is_featured' => $this->isFeatured
        ];
    }
}
