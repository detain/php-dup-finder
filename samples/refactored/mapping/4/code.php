<?php
declare(strict_types=1);

namespace App\Core\Api\Mapper;

use App\Domain\Entity\Product;
use App\Core\DTO\DTOInterface;

interface ApiVersionStrategy
{
    public function getVersion(): string;
    public function getDateFormat(): string;
    public function getExtraFields(): array;
    public function includeDeprecatedFields(): bool;
}

abstract class BaseApiProductMapper
{
    protected ProductTransformer $transformer;

    public function map(Product $product, DTOInterface $dto, ?ApiVersionStrategy $strategy = null): DTOInterface
    {
        $dto->id = $product->getId()->toString();
        $dto->sku = $product->getSku();
        $dto->name = $product->getName();
        $dto->slug = $product->getSlug();
        $dto->description = $product->getDescription();
        $dto->price = $product->getPrice()->getAmount();
        $dto->salePrice = $product->getSalePrice()?->getAmount();
        $dto->currency = $product->getCurrency()->code();
        $dto->categoryId = $product->getCategoryId()->toString();
        $dto->categoryName = $product->getCategory()?->getName();
        $dto->brandId = $product->getBrandId()?->toString();
        $dto->brandName = $product->getBrand()?->getName();
        $dto->status = $product->getStatus()->value;
        $dto->stockQuantity = $product->getStockQuantity();
        $dto->isInStock = $product->isInStock();
        $dto->images = $this->transformImages($product->getImages());
        $dto->attributes = $this->transformAttributes($product->getAttributes());
        $dto->rating = $product->getAverageRating();
        $dto->reviewCount = $product->getReviewCount();
        $dto->createdAt = $product->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->updatedAt = $product->getUpdatedAt()->format(\DateTimeInterface::ATOM);

        if ($strategy !== null) {
            foreach ($strategy->getExtraFields() as $field => $value) {
                $dto->{$field} = $value;
            }
        }

        return $dto;
    }

    protected function transformImages(array $images): array
    {
        return array_map(fn($img) => $this->transformer->transform($img), $images);
    }

    protected function transformAttributes(array $attributes): array
    {
        return $this->transformer->transformCollection($attributes);
    }
}
