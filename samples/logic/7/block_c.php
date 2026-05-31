<?php

declare(strict_types=1);

namespace App\ProductCatalog;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\InputSanitizer;
use Psr\Log\LoggerInterface;

final class ProductCatalogService
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly InputSanitizer $inputSanitizer,
        private readonly LoggerInterface $logger,
    ) {}

    public function updateProduct(int $productId, array $productData): Product
    {
        $product = $this->productRepository->findById($productId);

        if ($product === null) {
            throw new \RuntimeException('Product not found');
        }

        if (isset($productData['name'])) {
            $name = trim($productData['name']);

            if (strlen($name) < 2) {
                throw new \InvalidArgumentException('Product name is too short');
            }

            if (strlen($name) > 200) {
                throw new \InvalidArgumentException('Product name cannot exceed 200 characters');
            }

            if (!preg_match('/^[a-zA-Z0-9\s\-\_\.\!\?\&\(\)]+$/', $name)) {
                throw new \InvalidArgumentException('Product name contains invalid characters');
            }

            $product->setName($name);
        }

        if (isset($productData['description'])) {
            $description = trim($productData['description']);

            if (strlen($description) > 5000) {
                throw new \InvalidArgumentException('Description cannot exceed 5000 characters');
            }

            $product->setDescription($description);
        }

        if (isset($productData['sku'])) {
            $sku = trim(strtoupper($productData['sku']));

            if (strlen($sku) < 3) {
                throw new \InvalidArgumentException('SKU is too short');
            }

            if (strlen($sku) > 30) {
                throw new \InvalidArgumentException('SKU cannot exceed 30 characters');
            }

            if (!preg_match('/^[A-Z0-9\-]+$/', $sku)) {
                throw new \InvalidArgumentException('SKU must be alphanumeric with dashes only');
            }

            $product->setSku($sku);
        }

        if (isset($productData['price'])) {
            $price = (float) $productData['price'];

            if ($price <= 0) {
                throw new \InvalidArgumentException('Price must be greater than zero');
            }

            if ($price > 1000000) {
                throw new \InvalidArgumentException('Price cannot exceed 1,000,000');
            }

            $product->setPrice((int) ($price * 100));
        }

        if (isset($productData['weight'])) {
            $weight = (float) $productData['weight'];

            if ($weight <= 0) {
                throw new \InvalidArgumentException('Weight must be greater than zero');
            }

            if ($weight > 10000) {
                throw new \InvalidArgumentException('Weight cannot exceed 10,000');
            }

            $product->setWeight($weight);
        }

        $this->productRepository->save($product);

        $this->logger->info('Product updated', [
            'product_id' => $productId,
            'updated_fields' => array_keys($productData),
        ]);

        return $product;
    }

    public function updateInventory(int $productId, array $inventoryData): Product
    {
        $product = $this->productRepository->findById($productId);

        if ($product === null) {
            throw new \RuntimeException('Product not found');
        }

        if (isset($inventoryData['quantity'])) {
            $quantity = (int) $inventoryData['quantity'];

            if ($quantity < 0) {
                throw new \InvalidArgumentException('Quantity cannot be negative');
            }

            if ($quantity > 1000000) {
                throw new \InvalidArgumentException('Quantity cannot exceed 1,000,000');
            }

            $product->setQuantity($quantity);
        }

        if (isset($inventoryData['warehouse_location'])) {
            $location = trim($inventoryData['warehouse_location']);

            if (strlen($location) < 2) {
                throw new \InvalidArgumentException('Warehouse location is too short');
            }

            if (strlen($location) > 20) {
                throw new \InvalidArgumentException('Warehouse location cannot exceed 20 characters');
            }

            if (!preg_match('/^[A-Z0-9\-]+$/', $location)) {
                throw new \InvalidArgumentException('Warehouse location must be alphanumeric');
            }

            $product->setWarehouseLocation($location);
        }

        $this->productRepository->save($product);

        return $product;
    }
}
