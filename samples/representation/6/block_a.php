<?php
declare(strict_types=1);

namespace Catalog;

final class CatalogProduct
{
    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly string $title,
        public readonly string $slug,
        public readonly float $price,
        public readonly int $stockQty,
        public readonly string $category,
        public readonly ?string $imageUrl,
    ) {
        if ($price < 0) {
            throw new \InvalidArgumentException('Price negative');
        }
        if ($sku === '') {
            throw new \InvalidArgumentException('SKU required');
        }
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            sku: (string)$row['sku'],
            title: (string)$row['name'],
            slug: (string)$row['slug'],
            price: (float)$row['price'],
            stockQty: (int)$row['stock'],
            category: (string)$row['category'],
            imageUrl: $row['image'] ?? null,
        );
    }

    public function isAvailable(): bool
    {
        return $this->stockQty > 0;
    }

    public function priceFormatted(): string
    {
        return number_format($this->price, 2);
    }
}

final class CatalogController
{
    public function show(int $id, \PDO $pdo): ?CatalogProduct
    {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? CatalogProduct::fromRow($row) : null;
    }
}
