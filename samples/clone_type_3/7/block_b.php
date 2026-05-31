<?php

declare(strict_types=1);

namespace App\Transform;

use App\Entity\Product;
use App\Entity\ProductCategory;

final class ProductMapper
{
    public function toArray(Product $product): array
    {
        return [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'category' => [
                'id' => $product->getCategory()->getId(),
                'name' => $product->getCategory()->getName(),
                'slug' => $product->getCategory()->getSlug(),
            ],
            'price' => $product->getPrice(),
            'stock' => $product->getStock(),
            'status' => $product->getStatus(),
            'created_at' => $product->getCreatedAt()->format('c'),
            'updated_at' => $product->getUpdatedAt()->format('c'),
        ];
    }

    public function toSummaryArray(Product $product): array
    {
        return [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'category_name' => $product->getCategory()->getName(),
            'price' => $product->getPrice(),
            'stock' => $product->getStock(),
        ];
    }

    public function toCsvRow(Product $product): array
    {
        return [
            $product->getSku(),
            $product->getName(),
            $product->getCategory()->getName(),
            number_format($product->getPrice(), 2),
            $product->getStock(),
            $product->getStatus(),
            $product->getCreatedAt()->format('Y-m-d H:i:s'),
        ];
    }

    public function toFlatArray(Product $product): array
    {
        return [
            'product_id' => $product->getId(),
            'product_sku' => $product->getSku(),
            'product_name' => $product->getName(),
            'product_description' => $product->getDescription(),
            'product_price' => $product->getPrice(),
            'product_stock' => $product->getStock(),
            'product_status' => $product->getStatus(),
            'category_id' => $product->getCategory()->getId(),
            'category_name' => $product->getCategory()->getName(),
            'category_slug' => $product->getCategory()->getSlug(),
        ];
    }
}
