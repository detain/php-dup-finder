<?php

declare(strict_types=1);

namespace App\Entity;

class Order
{
    private string $id;
    private string $userId;
    private array $items;
    private float $totalAmount;
    private string $currency;
    private string $status;
    private ?string $shippingAddress;
    private ?string $billingAddress;
    private DateTimeImmutable $createdAt;
    private ?DateTimeImmutable $updatedAt;
    private ?DateTimeImmutable $shippedAt;

    public function __construct(
        string $id,
        string $userId,
        array $items,
        float $totalAmount,
        string $currency,
        string $status,
        ?string $shippingAddress,
        ?string $billingAddress,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $shippedAt
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->items = $items;
        $this->totalAmount = $totalAmount;
        $this->currency = $currency;
        $this->status = $status;
        $this->shippingAddress = $shippingAddress;
        $this->billingAddress = $billingAddress;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->shippedAt = $shippedAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'items' => array_map(fn($item) => [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price']
            ], $this->items),
            'total' => [
                'amount' => $this->totalAmount,
                'currency' => $this->currency
            ],
            'status' => $this->status,
            'shipping_address' => $this->shippingAddress,
            'billing_address' => $this->billingAddress,
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt?->format('c'),
            'shipped_at' => $this->shippedAt?->format('c'),
            'meta' => [
                'type' => 'order',
                'serialized_at' => (new DateTimeImmutable())->format('c')
            ]
        ];
    }

    public function toCompactArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'total' => [
                'amount' => $this->totalAmount,
                'currency' => $this->currency
            ],
            'status' => $this->status,
            'created_at' => $this->createdAt->format('c')
        ];
    }

    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'total_amount' => $this->totalAmount,
            'currency' => $this->currency,
            'status' => $this->status,
            'item_count' => count($this->items)
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'items' => array_map(fn($item) => [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price']
            ], $this->items),
            'total' => [
                'amount' => $this->totalAmount,
                'currency' => $this->currency
            ],
            'status' => $this->status,
            'created_at' => $this->createdAt->format('c')
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

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getTotalAmount(): float
    {
        return $this->totalAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getShippingAddress(): ?string
    {
        return $this->shippingAddress;
    }

    public function getBillingAddress(): ?string
    {
        return $this->billingAddress;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getShippedAt(): ?DateTimeImmutable
    {
        return $this->shippedAt;
    }
}
