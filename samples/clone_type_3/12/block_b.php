<?php

declare(strict_types=1);

namespace App\Hydrator;

use App\Entity\Product;
use App\Entity\ProductSpecification;

final class ProductHydrator
{
    public function hydrateFromArray(Product $product, array $data): Product
    {
        if (isset($data['name'])) {
            $product->setName($data['name']);
        }

        if (isset($data['sku'])) {
            $product->setSku($data['sku']);
        }

        if (isset($data['description'])) {
            $product->setDescription($data['description']);
        }

        if (isset($data['price'])) {
            $product->setPrice((float) $data['price']);
        }

        if (isset($data['stock'])) {
            $product->setStock((int) $data['stock']);
        }

        if (isset($data['status'])) {
            $product->setStatus($data['status']);
        }

        return $product;
    }

    public function hydrateSpecificationsFromArray(Product $product, array $specsData): Product
    {
        $specs = [];

        foreach ($specsData as $specData) {
            $spec = new ProductSpecification(
                $specData['name'],
                $specData['value'],
                $specData['unit'] ?? null
            );

            if (isset($specData['group'])) {
                $spec->setGroup($specData['group']);
            }

            $specs[] = $spec;
        }

        $product->setSpecifications($specs);

        return $product;
    }

    public function extractToArray(Product $product): array
    {
        return [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'category_id' => $product->getCategoryId(),
            'price' => $product->getPrice(),
            'stock' => $product->getStock(),
            'status' => $product->getStatus(),
            'featured' => $product->isFeatured(),
            'created_at' => $product->getCreatedAt()?->format('c'),
            'updated_at' => $product->getUpdatedAt()?->format('c'),
        ];
    }

    public function extractSpecificationsToArray(Product $product): array
    {
        $specs = [];

        foreach ($product->getSpecifications() as $spec) {
            $specs[] = [
                'name' => $spec->getName(),
                'value' => $spec->getValue(),
                'unit' => $spec->getUnit(),
                'group' => $spec->getGroup(),
            ];
        }

        return $specs;
    }
}
