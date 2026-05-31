<?php
declare(strict_types=1);

namespace App\Core\Catalog\Product\Mapper;

use App\Domain\Entity\Product;
use App\Core\DTO\DTOInterface;

interface ProductMapperStrategy
{
    public function shouldIncludeCostPrice(): bool;
    public function shouldIncludeSeoFields(): bool;
    public function getCustomFields(): array;
}

abstract class BaseProductMapper
{
    public function map(Product $product, DTOInterface $dto, ?ProductMapperStrategy $strategy = null): DTOInterface
    {
        $dto->id = $product->getId()->toString();
        $dto->sku = $product->getSku();
        $dto->name = $product->getName();
        $dto->slug = $product->getSlug();
        $dto->description = $product->getDescription();
        $dto->shortDescription = $product->getShortDescription();
        $dto->price = $product->getPrice()->getAmount();
        $dto->salePrice = $product->getSalePrice()?->getAmount();
        $dto->currency = $product->getCurrency()->code();
        $dto->categoryId = $product->getCategoryId()->toString();
        $dto->categoryName = $product->getCategory()?->getName();
        $dto->brandId = $product->getBrandId()?->toString();
        $dto->brandName = $product->getBrand()?->getName();
        $dto->status = $product->getStatus()->value;
        $dto->stockQuantity = $product->getStockQuantity();
        $dto->lowStockThreshold = $product->getLowStockThreshold();
        $dto->images = $this->mapImages($product->getImages());
        $dto->attributes = $this->mapAttributes($product->getAttributes());
        $dto->tags = $product->getTags();
        $dto->weight = $product->getWeight();
        $dto->dimensions = $product->getDimensions();
        $dto->createdAt = $product->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->updatedAt = $product->getUpdatedAt()->format(\DateTimeInterface::ATOM);
        $dto->publishedAt = $product->getPublishedAt()?->format(\DateTimeInterface::ATOM);

        if ($strategy?->shouldIncludeCostPrice() === true) {
            $dto->costPrice = $product->getCostPrice()?->getAmount();
        }
        if ($strategy?->shouldIncludeSeoFields() === true) {
            $dto->seoTitle = $product->getSeoTitle();
            $dto->seoDescription = $product->getSeoDescription();
        }

        return $dto;
    }

    protected function mapImages(array $images): array
    {
        return array_map(fn($img) => [
            'url' => $img->getUrl(),
            'alt' => $img->getAlt(),
            'isPrimary' => $img->isPrimary(),
            'sortOrder' => $img->getSortOrder(),
        ], $images);
    }

    protected function mapAttributes(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $attr) {
            $result[$attr->getName()] = [
                'value' => $attr->getValue(),
                'unit' => $attr->getUnit(),
            ];
        }
        return $result;
    }
}

final class ProductMapper extends BaseProductMapper {}
