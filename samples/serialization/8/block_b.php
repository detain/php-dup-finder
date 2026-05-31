<?php

declare(strict_types=1);

namespace App\Repository\Mapper;

class ProductResultMapper
{
    public function map(array $row): ?Product
    {
        if ($row === null || count($row) === 0) {
            return null;
        }

        return new Product(
            (string)$row['id'],
            (string)$row['name'],
            isset($row['description']) && $row['description'] !== null ? (string)$row['description'] : null,
            isset($row['price']) ? (float)$row['price'] : 0.0,
            isset($row['currency']) ? (string)$row['currency'] : 'USD',
            (string)$row['category_id'],
            isset($row['image_url']) && $row['image_url'] !== null ? (string)$row['image_url'] : null,
            isset($row['stock_quantity']) ? (int)$row['stock_quantity'] : 0,
            isset($row['is_available']) ? (bool)$row['is_available'] : true,
            isset($row['created_at']) ? new \DateTimeImmutable($row['created_at']) : new \DateTimeImmutable(),
            isset($row['updated_at']) && $row['updated_at'] !== null ? new \DateTimeImmutable($row['updated_at']) : null,
            isset($row['tags']) ? $this->unserializeTags($row['tags']) : []
        );
    }

    public function mapMany(array $rows): array
    {
        return array_map(fn(array $row) => $this->map($row), $rows);
    }

    public function mapToArray(Product $product): array
    {
        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'price' => $product->getPrice(),
            'currency' => $product->getCurrency(),
            'category_id' => $product->getCategoryId(),
            'image_url' => $product->getImageUrl(),
            'stock_quantity' => $product->getStockQuantity(),
            'is_available' => $product->isAvailable() ? 1 : 0,
            'tags' => $this->serializeTags($product->getTags()),
            'created_at' => $product->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $product->getUpdatedAt()?->format('Y-m-d H:i:s')
        ];
    }

    private function unserializeTags(string $tagsJson): array
    {
        if (empty($tagsJson)) {
            return [];
        }

        $decoded = json_decode($tagsJson, true);

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function serializeTags(array $tags): string
    {
        return json_encode($tags);
    }
}
