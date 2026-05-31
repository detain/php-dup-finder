<?php
declare(strict_types=1);

namespace Cart\Snapshot;

final class CartProductSnapshot
{
    public int $productId;
    public string $productSku;
    public string $productName;
    public string $productSlug;
    public int $unitPriceCents;
    public bool $availableAtSnapshot;
    public string $categoryLabel;
    public ?string $thumbnailUrl;
    public \DateTimeImmutable $capturedAt;

    public function capture(array $product): void
    {
        if (empty($product['sku'])) {
            throw new \InvalidArgumentException('Cart snapshot needs SKU');
        }
        if ((float)$product['price'] < 0) {
            throw new \InvalidArgumentException('Cart snapshot price negative');
        }
        $this->productId = (int)$product['id'];
        $this->productSku = (string)$product['sku'];
        $this->productName = (string)$product['name'];
        $this->productSlug = (string)$product['slug'];
        $this->unitPriceCents = (int) round(((float)$product['price']) * 100);
        $this->availableAtSnapshot = (int)$product['stock'] > 0;
        $segments = explode('/', (string)$product['category']);
        $this->categoryLabel = end($segments) ?: '';
        $this->thumbnailUrl = $product['image'] ?? null;
        $this->capturedAt = new \DateTimeImmutable();
    }

    public function priceDisplay(): string
    {
        return number_format($this->unitPriceCents / 100, 2);
    }
}

final class CartService
{
    public function addProduct(array $raw): CartProductSnapshot
    {
        $snap = new CartProductSnapshot();
        $snap->capture($raw);
        return $snap;
    }
}
