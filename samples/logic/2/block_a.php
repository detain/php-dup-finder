<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\StockManager;
use Psr\Log\LoggerInterface;

final class ProductInventoryService
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly StockManager $stockManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function addStock(int $productId, int $quantity, string $reason): Product
    {
        $product = $this->productRepository->findById($productId);

        if ($product === null) {
            throw new \RuntimeException('Product not found');
        }

        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive');
        }

        if ($quantity > 10000) {
            throw new \InvalidArgumentException('Cannot add more than 10000 units at once');
        }

        if (!$this->isValidReason($reason)) {
            throw new \InvalidArgumentException('Invalid stock adjustment reason');
        }

        if ($product->getStatus() === 'discontinued') {
            throw new \InvalidArgumentException('Cannot adjust stock for discontinued product');
        }

        if ($product->getStatus() === 'pending_deletion') {
            throw new \InvalidArgumentException('Cannot adjust stock for product pending deletion');
        }

        $currentStock = $product->getStockQuantity();
        $newStock = $currentStock + $quantity;

        $this->stockManager->adjustStock($productId, $quantity, $reason);
        $product->setStockQuantity($newStock);
        $product->setLastStockUpdate(new \DateTimeImmutable());

        $this->productRepository->save($product);

        $this->logger->info('Stock added successfully', [
            'product_id' => $productId,
            'quantity' => $quantity,
            'new_stock' => $newStock,
            'reason' => $reason,
        ]);

        return $product;
    }

    public function removeStock(int $productId, int $quantity, string $reason): Product
    {
        $product = $this->productRepository->findById($productId);

        if ($product === null) {
            throw new \RuntimeException('Product not found');
        }

        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive');
        }

        if ($quantity > 10000) {
            throw new \InvalidArgumentException('Cannot remove more than 10000 units at once');
        }

        if (!$this->isValidReason($reason)) {
            throw new \InvalidArgumentException('Invalid stock adjustment reason');
        }

        if ($product->getStatus() === 'discontinued') {
            throw new \InvalidArgumentException('Cannot adjust stock for discontinued product');
        }

        if ($product->getStatus() === 'pending_deletion') {
            throw new \InvalidArgumentException('Cannot adjust stock for product pending deletion');
        }

        $currentStock = $product->getStockQuantity();

        if ($currentStock < $quantity) {
            throw new \InvalidArgumentException('Insufficient stock available');
        }

        $newStock = $currentStock - $quantity;

        $this->stockManager->adjustStock($productId, -$quantity, $reason);
        $product->setStockQuantity($newStock);
        $product->setLastStockUpdate(new \DateTimeImmutable());

        $this->productRepository->save($product);

        $this->logger->info('Stock removed successfully', [
            'product_id' => $productId,
            'quantity' => $quantity,
            'new_stock' => $newStock,
            'reason' => $reason,
        ]);

        return $product;
    }

    private function isValidReason(string $reason): bool
    {
        $validReasons = [
            'purchase_order',
            'return',
            'transfer_in',
            'adjustment',
            'correction',
            'found',
            'sample',
        ];

        return in_array($reason, $validReasons, true);
    }
}
