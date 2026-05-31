<?php
declare(strict_types=1);

namespace App\Api\Rest\Product\V1\Mapper;

use App\Domain\Entity\Product;
use App\Api\Rest\Product\V1\DTO\ProductResponse;
use App\Api\Rest\Product\V1\DTO\ProductListResponse;
use App\Api\Rest\Product\V1\Transformer\ProductTransformer;

final readonly class ProductApiMapper
{
    public function __construct(
        private ProductTransformer $transformer,
    ) {}

    public function toResponse(Product $product): ProductResponse
    {
        $response = new ProductResponse();
        $response->id = $product->getId()->toString();
        $response->sku = $product->getSku();
        $response->name = $product->getName();
        $response->slug = $product->getSlug();
        $response->description = $product->getDescription();
        $response->price = $product->getPrice()->getAmount();
        $response->salePrice = $product->getSalePrice()?->getAmount();
        $response->currency = $product->getCurrency()->code();
        $response->categoryId = $product->getCategoryId()->toString();
        $response->categoryName = $product->getCategory()?->getName();
        $response->brandId = $product->getBrandId()?->toString();
        $response->brandName = $product->getBrand()?->getName();
        $response->status = $product->getStatus()->value;
        $response->stockQuantity = $product->getStockQuantity();
        $response->isInStock = $product->isInStock();
        $response->images = $this->transformImages($product->getImages());
        $response->attributes = $this->transformAttributes($product->getAttributes());
        $response->rating = $product->getAverageRating();
        $response->reviewCount = $product->getReviewCount();
        $response->createdAt = $product->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $response->updatedAt = $product->getUpdatedAt()->format(\DateTimeInterface::ATOM);

        return $response;
    }

    public function toListResponse(array $products): ProductListResponse
    {
        $response = new ProductListResponse();
        $response->data = array_map(
            fn($p) => $this->toResponse($p),
            $products
        );
        $response->meta = [
            'total' => count($products),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        return $response;
    }

    private function transformImages(array $images): array
    {
        return array_map(fn($img) => $this->transformer->transform($img), $images);
    }

    private function transformAttributes(array $attributes): array
    {
        return $this->transformer->transformCollection($attributes);
    }
}
