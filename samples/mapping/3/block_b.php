<?php
declare(strict_types=1);

namespace App\Catalog\Product\Infrastructure\Export\Mapper;

use App\Domain\Entity\Product;
use App\Catalog\Product\Infrastructure\DTO\ProductExportDTO;

final readonly class ProductExportMapper
{
    public function toExportDTO(Product $product): ProductExportDTO
    {
        $dto = new ProductExportDTO();
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
        $dto->exportedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $dto->exportBatchId = $this->generateExportBatchId();

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

    private function generateExportBatchId(): string
    {
        return 'EXP-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    }
}
