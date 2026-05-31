<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\Serializer;
use App\Exception\ApiException;
use Psr\Log\LoggerInterface;

final class ProductApiController
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly Serializer $serializer,
        private readonly LoggerInterface $logger,
    ) {}

    public function getProduct(int $id): array
    {
        $product = $this->productRepository->find($id);

        if ($product === null) {
            throw new ApiException('Product not found', 404);
        }

        return $this->serializer->normalize($product, ['detail', 'categories', 'images']);
    }

    public function getProductsByCategory(string $categorySlug, int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;

        $products = $this->productRepository->findByCategory($categorySlug, $limit, $offset);
        $total = $this->productRepository->countByCategory($categorySlug);

        return [
            'data' => $this->serializer->normalize($products, ['list']),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function createProduct(array $data): array
    {
        $errors = $this->validateProductData($data);

        if (!empty($errors)) {
            throw new ApiException('Validation failed', 422, $errors);
        }

        $product = new Product(
            $data['sku'],
            $data['name'],
            $data['price'],
            $data['description'] ?? null
        );

        $product->setStock($data['stock'] ?? 0);
        $product->setCategoryId($data['category_id'] ?? null);

        $this->productRepository->save($product);

        $this->logger->info('Product created via API', [
            'product_id' => $product->getId(),
            'sku' => $data['sku'],
        ]);

        return $this->serializer->normalize($product, ['detail']);
    }

    public function updateProduct(int $id, array $data): array
    {
        $product = $this->productRepository->find($id);

        if ($product === null) {
            throw new ApiException('Product not found', 404);
        }

        if (isset($data['name'])) {
            $product->setName($data['name']);
        }

        if (isset($data['price'])) {
            $product->setPrice($data['price']);
        }

        if (isset($data['stock'])) {
            $product->setStock($data['stock']);
        }

        $this->productRepository->save($product);

        $this->logger->info('Product updated via API', [
            'product_id' => $product->getId(),
            'updates' => array_keys($data),
        ]);

        return $this->serializer->normalize($product, ['detail']);
    }

    public function deleteProduct(int $id): array
    {
        $product = $this->productRepository->find($id);

        if ($product === null) {
            throw new ApiException('Product not found', 404);
        }

        if (!$product->canBeDeleted()) {
            throw new ApiException('Product cannot be deleted', 422);
        }

        $product->markAsDeleted();
        $this->productRepository->save($product);

        $this->logger->info('Product deleted via API', [
            'product_id' => $id,
        ]);

        return ['success' => true, 'message' => 'Product deleted'];
    }

    private function validateProductData(array $data): array
    {
        $errors = [];

        if (empty($data['sku'])) {
            $errors['sku'] = 'SKU is required';
        } elseif ($this->productRepository->findBySku($data['sku']) !== null) {
            $errors['sku'] = 'SKU already exists';
        }

        if (empty($data['name'])) {
            $errors['name'] = 'Name is required';
        } elseif (strlen($data['name']) < 3) {
            $errors['name'] = 'Name must be at least 3 characters';
        }

        if (empty($data['price'])) {
            $errors['price'] = 'Price is required';
        } elseif (!is_numeric($data['price']) || $data['price'] < 0) {
            $errors['price'] = 'Price must be a positive number';
        }

        if (isset($data['stock']) && (!is_int($data['stock']) || $data['stock'] < 0)) {
            $errors['stock'] = 'Stock must be a non-negative integer';
        }

        return $errors;
    }
}
