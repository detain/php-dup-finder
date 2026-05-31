<?php
declare(strict_types=1);

namespace RetailPlatform\API\Formatter;

use Psr\Log\LoggerInterface;

final class ProductResponseFormatter
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function format(array $productData): array
    {
        $this->logger->debug('Formatting product response', ['sku' => $productData['sku'] ?? 'unknown']);

        if (!isset($productData['id'])) {
            throw new \InvalidArgumentException('Product ID is required');
        }

        if (!isset($productData['sku']) || empty(trim($productData['sku']))) {
            throw new \InvalidArgumentException('Product SKU is required');
        }

        if (!isset($productData['name']) || empty(trim($productData['name']))) {
            throw new \InvalidArgumentException('Product name is required');
        }

        if (!is_numeric($productData['price'] ?? null)) {
            throw new \InvalidArgumentException('Product price must be numeric');
        }

        if (($productData['price'] ?? 0) < 0) {
            throw new \InvalidArgumentException('Product price cannot be negative');
        }

        if (isset($productData['stock_quantity']) && !is_int($productData['stock_quantity'])) {
            throw new \InvalidArgumentException('Stock quantity must be an integer');
        }

        if (isset($productData['stock_quantity']) && $productData['stock_quantity'] < 0) {
            throw new \InvalidArgumentException('Stock quantity cannot be negative');
        }

        if (isset($productData['categories']) && !is_array($productData['categories'])) {
            throw new \InvalidArgumentException('Categories must be an array');
        }

        if (isset($productData['tags']) && !is_array($productData['tags'])) {
            throw new \InvalidArgumentException('Tags must be an array');
        }

        if (isset($productData['images']) && !is_array($productData['images'])) {
            throw new \InvalidArgumentException('Images must be an array');
        }

        return $this->buildResponse($productData);
    }

    public function formatList(array $products): array
    {
        return array_map(fn($product) => $this->format($product), $products);
    }

    private function buildResponse(array $productData): array
    {
        return [
            'id' => (int)$productData['id'],
            'sku' => trim($productData['sku']),
            'name' => trim($productData['name']),
            'description' => trim($productData['description'] ?? ''),
            'price' => (float)$productData['price'],
            'currency' => $productData['currency'] ?? 'USD',
            'stock_quantity' => isset($productData['stock_quantity'])
                ? (int)$productData['stock_quantity']
                : null,
            'categories' => array_map('trim', $productData['categories'] ?? []),
            'tags' => array_map('trim', $productData['tags'] ?? []),
            'images' => $this->formatImages($productData['images'] ?? []),
            'metadata' => $this->formatMetadata($productData['metadata'] ?? []),
            'formatted_price' => $this->formatPrice($productData['price'], $productData['currency'] ?? 'USD'),
            'in_stock' => ($productData['stock_quantity'] ?? 0) > 0,
            'created_at' => $productData['created_at'] ?? null,
            'updated_at' => $productData['updated_at'] ?? null,
        ];
    }

    private function formatImages(array $images): array
    {
        return array_map(function ($image) {
            if (is_string($image)) {
                return ['url' => $image, 'alt' => '', 'is_primary' => false];
            }
            return [
                'url' => $image['url'] ?? '',
                'alt' => $image['alt'] ?? '',
                'is_primary' => (bool)($image['is_primary'] ?? false),
            ];
        }, $images);
    }

    private function formatMetadata(array $metadata): array
    {
        $formatted = [];
        foreach ($metadata as $key => $value) {
            $formatted[$key] = is_scalar($value) ? (string)$value : json_encode($value);
        }
        return $formatted;
    }

    private function formatPrice(float $price, string $currency): string
    {
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format($price, 2);
    }
}
