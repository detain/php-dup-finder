<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Hydrator;

use App\Domain\Entity\Product;
use Doctrine\DBAL\Result;
use App\Infrastructure\Persistence\Doctrine\Types\UlidType;

final readonly class ProductHydrator
{
    public function __construct(
        private ProductFactory $factory,
    ) {}

    public function hydrateOne(Result $result): ?Product
    {
        $row = $result->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return $this->hydrateRow($row);
    }

    public function hydrateAll(Result $result): array
    {
        $products = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $products[] = $this->hydrateRow($row);
        }

        return $products;
    }

    private function hydrateRow(array $row): Product
    {
        $product = $this->factory->create();
        $product->setId($this->factory->createUlid($row['id']));
        $product->setSku($row['sku']);
        $product->setName($row['name']);
        $product->setSlug($row['slug']);
        $product->setDescription($row['description']);
        $product->setShortDescription($row['short_description']);
        $product->setPrice($this->factory->createMoney($row['price'], $row['currency']));
        $product->setSalePrice($row['sale_price'] ? $this->factory->createMoney($row['sale_price'], $row['currency']) : null);
        $product->setCostPrice($row['cost_price'] ? $this->factory->createMoney($row['cost_price'], $row['currency']) : null);
        $product->setCurrency($row['currency']);
        $product->setCategoryId($this->factory->createUlid($row['category_id']));
        $product->setBrandId($row['brand_id'] ? $this->factory->createUlid($row['brand_id']) : null);
        $product->setStatus($row['status']);
        $product->setStockQuantity((int)$row['stock_quantity']);
        $product->setLowStockThreshold((int)$row['low_stock_threshold']);
        $product->setCreatedAt(new \DateTimeImmutable($row['created_at']));
        $product->setUpdatedAt(new \DateTimeImmutable($row['updated_at']));
        $product->setPublishedAt($row['published_at'] ? new \DateTimeImmutable($row['published_at']) : null);

        if (isset($row['images'])) {
            $product->setImages(json_decode($row['images'], true));
        }

        return $product;
    }
}
