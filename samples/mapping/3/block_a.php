<?php
declare(strict_types=1);

namespace App\Catalog\Product\Application\Mapper;

use App\Domain\Entity\Product;
use App\Catalog\Product\Application\DTO\ProductEntityDTO;
use App\Catalog\Product\Application\DTO\ProductSearchResultDTO;
use App\Catalog\Product\Application\DTO\ProductRecommendationDTO;

final readonly class ProductApplicationMapper
{
    public function toEntityDTO(Product $product): ProductEntityDTO
    {
        $dto = new ProductEntityDTO();
        $dto->id = $product->getId()->toString();
        $dto->sku = $product->getSku();
        $dto->name = $product->getName();
        $dto->slug = $product->getSlug();
        $dto->description = $product->getDescription();
        $dto->shortDescription = $product->getShortDescription();
        $dto->price = $product->getPrice()->getAmount();
        $dto->salePrice = $product->getSalePrice()?->getAmount();
        $dto->costPrice = $product->getCostPrice()?->getAmount();
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
        $dto->seoTitle = $product->getSeoTitle();
        $dto->seoDescription = $product->getSeoDescription();
        $dto->createdAt = $product->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->updatedAt = $product->getUpdatedAt()->format(\DateTimeInterface::ATOM);
        $dto->publishedAt = $product->getPublishedAt()?->format(\DateTimeInterface::ATOM);

        return $dto;
    }

    public function toSearchResultDTO(Product $product, float $relevanceScore = 0.0): ProductSearchResultDTO
    {
        $dto = new ProductSearchResultDTO();
        $dto->id = $product->getId()->toString();
        $dto->sku = $product->getSku();
        $dto->name = $product->getName();
        $dto->slug = $product->getSlug();
        $dto->description = $product->getDescription();
        $dto->shortDescription = $product->getShortDescription();
        $dto->price = $product->getPrice()->getAmount();
        $dto->salePrice = $product->getSalePrice()?->getAmount();
        $dto->costPrice = $product->getCostPrice()?->getAmount();
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
        $dto->seoTitle = $product->getSeoTitle();
        $dto->seoDescription = $product->getSeoDescription();
        $dto->createdAt = $product->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->updatedAt = $product->getUpdatedAt()->format(\DateTimeInterface::ATOM);
        $dto->publishedAt = $product->getPublishedAt()?->format(\DateTimeInterface::ATOM);
        $dto->relevanceScore = $relevanceScore;
        $dto->inStock = $product->isInStock();

        return $dto;
    }

    public function toRecommendationDTO(Product $product, float $similarityScore = 0.0): ProductRecommendationDTO
    {
        $dto = new ProductRecommendationDTO();
        $dto->id = $product->getId()->toString();
        $dto->sku = $product->getSku();
        $dto->name = $product->getName();
        $dto->slug = $product->getSlug();
        $dto->description = $product->getDescription();
        $dto->shortDescription = $product->getShortDescription();
        $dto->price = $product->getPrice()->getAmount();
        $dto->salePrice = $product->getSalePrice()?->getAmount();
        $dto->costPrice = $product->getCostPrice()?->getAmount();
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
        $dto->seoTitle = $product->getSeoTitle();
        $dto->seoDescription = $product->getSeoDescription();
        $dto->createdAt = $product->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->updatedAt = $product->getUpdatedAt()->format(\DateTimeInterface::ATOM);
        $dto->publishedAt = $product->getPublishedAt()?->format(\DateTimeInterface::ATOM);
        $dto->similarityScore = $similarityScore;
        $dto->reason = $this->generateRecommendationReason($product);

        return $dto;
    }

    private function mapImages(array $images): array
    {
        return array_map(fn($img) => [
            'url' => $img->getUrl(),
            'alt' => $img->getAlt(),
            'isPrimary' => $img->isPrimary(),
            'sortOrder' => $img->getSortOrder(),
        ], $images);
    }

    private function mapAttributes(array $attributes): array
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

    private function generateRecommendationReason(Product $product): string
    {
        return 'Based on your recent browsing history';
    }
}
