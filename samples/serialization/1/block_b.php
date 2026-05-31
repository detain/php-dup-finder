<?php

declare(strict_types=1);

namespace App\Entity;

class Product
{
    private string $id;
    private string $name;
    private ?string $description;
    private float $price;
    private string $currency;
    private string $categoryId;
    private ?string $imageUrl;
    private int $stockQuantity;
    private bool $isAvailable;
    private DateTimeImmutable $createdAt;
    private ?DateTimeImmutable $updatedAt;
    private array $tags;

    public function __construct(
        string $id,
        string $name,
        ?string $description,
        float $price,
        string $currency,
        string $categoryId,
        ?string $imageUrl,
        int $stockQuantity,
        bool $isAvailable,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
        array $tags
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->description = $description;
        $this->price = $price;
        $this->currency = $currency;
        $this->categoryId = $categoryId;
        $this->imageUrl = $imageUrl;
        $this->stockQuantity = $stockQuantity;
        $this->isAvailable = $isAvailable;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->tags = $tags;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => [
                'amount' => $this->price,
                'currency' => $this->currency
            ],
            'category_id' => $this->categoryId,
            'image_url' => $this->imageUrl,
            'stock_quantity' => $this->stockQuantity,
            'is_available' => $this->isAvailable,
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt?->format('c'),
            'tags' => $this->tags,
            'meta' => [
                'type' => 'product',
                'serialized_at' => (new DateTimeImmutable())->format('c')
            ]
        ];
    }

    public function toCompactArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => [
                'amount' => $this->price,
                'currency' => $this->currency
            ],
            'image_url' => $this->imageUrl,
            'is_available' => $this->isAvailable
        ];
    }

    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'currency' => $this->currency,
            'is_available' => $this->isAvailable
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => [
                'amount' => $this->price,
                'currency' => $this->currency
            ],
            'image_url' => $this->imageUrl,
            'is_available' => $this->isAvailable,
            'tags' => $this->tags
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCategoryId(): string
    {
        return $this->categoryId;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getStockQuantity(): int
    {
        return $this->stockQuantity;
    }

    public function isAvailable(): bool
    {
        return $this->isAvailable;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getTags(): array
    {
        return $this->tags;
    }
}
