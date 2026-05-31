<?php

declare(strict_types=1);

namespace App\Dto;

class ProductArrayConverter
{
    public function fromEntity(Product $product): array
    {
        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'price_amount' => $product->getPrice(),
            'price_currency' => $product->getCurrency(),
            'category_id' => $product->getCategoryId(),
            'image_url' => $product->getImageUrl(),
            'stock_quantity' => $product->getStockQuantity(),
            'is_available' => $product->isAvailable(),
            'created_at' => $this->formatDateTime($product->getCreatedAt()),
            'updated_at' => $this->formatNullableDateTime($product->getUpdatedAt()),
            'tags' => $product->getTags()
        ];
    }

    public function toEntity(array $data): Product
    {
        return new Product(
            $data['id'],
            $data['name'],
            $data['description'] ?? null,
            $data['price_amount'],
            $data['price_currency'],
            $data['category_id'],
            $data['image_url'] ?? null,
            $data['stock_quantity'] ?? 0,
            $data['is_available'] ?? true,
            $this->parseDateTime($data['created_at']),
            isset($data['updated_at']) ? $this->parseDateTime($data['updated_at']) : null,
            $data['tags'] ?? []
        );
    }

    public function fromEntityCompact(Product $product): array
    {
        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'price_amount' => $product->getPrice(),
            'price_currency' => $product->getCurrency(),
            'is_available' => $product->isAvailable()
        ];
    }

    public function fromEntitySummary(Product $product): array
    {
        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'price' => $product->getPrice(),
            'currency' => $product->getCurrency()
        ];
    }

    public function fromEntities(array $products): array
    {
        return array_map(fn(Product $product) => $this->fromEntity($product), $products);
    }

    private function formatDateTime(\DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }

    private function formatNullableDateTime(?\DateTimeInterface $dateTime): ?string
    {
        return $dateTime?->format('Y-m-d H:i:s');
    }

    private function parseDateTime(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }
}
