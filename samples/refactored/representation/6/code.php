<?php
declare(strict_types=1);

namespace App\Product;

final class Product
{
    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly string $title,
        public readonly string $slug,
        public readonly int $priceCents,
        public readonly int $stockQty,
        public readonly string $categoryPath,
        public readonly ?string $imageUrl,
    ) {
        if ($priceCents < 0) throw new \InvalidArgumentException('Price negative');
        if ($sku === '') throw new \InvalidArgumentException('SKU required');
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int)$row['id'],
            (string)$row['sku'],
            (string)$row['name'],
            (string)$row['slug'],
            (int) round(((float)$row['price']) * 100),
            (int)$row['stock'],
            (string)$row['category'],
            $row['image'] ?? null,
        );
    }

    public function isAvailable(): bool { return $this->stockQty > 0; }

    public function priceFormatted(): string { return number_format($this->priceCents / 100, 2); }

    public function categorySegments(): array { return explode('/', $this->categoryPath); }

    public function categoryLeaf(): string
    {
        $seg = $this->categorySegments();
        return end($seg) ?: '';
    }

    public function toSearchDoc(): array
    {
        return [
            'product_id' => $this->id,
            'sku_keyword' => $this->sku,
            'title_text' => $this->title,
            'url_slug' => $this->slug,
            'price_cents' => $this->priceCents,
            'in_stock' => $this->isAvailable(),
            'stock_level' => $this->stockQty,
            'category_path' => $this->categorySegments(),
            'image' => $this->imageUrl,
        ];
    }
}
