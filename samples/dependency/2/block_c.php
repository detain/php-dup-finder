<?php

declare(strict_types=1);

namespace App\Domain\Product\Repository;

use App\Domain\Database\DatabaseConnection;
use App\Domain\Product\Entity\Product;
use App\Domain\Product\ValueObject\ProductCategory;

/**
 * Product repository implementation with manual database connection injection.
 * The DatabaseConnection is manually injected here, duplicated from
 * UserRepository, OrderRepository, and other repositories.
 */
class ProductRepository implements ProductRepositoryInterface
{
    private DatabaseConnection $db;
    private string $table = 'products';
    private string $inventoryTable = 'product_inventory';
    private string $imagesTable = 'product_images';

    public function __construct(DatabaseConnection $db)
    {
        $this->db = $db;
    }

    public function findById(string $id): ?Product
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1";

        $result = $this->db->query($sql, [$id]);

        if ($result->numRows() === 0) {
            return null;
        }

        $product = $this->hydrateProduct($result->fetch());
        $product->setInventory($this->getInventory($id));
        $product->setImages($this->getImages($id));

        return $product;
    }

    public function findBySku(string $sku): ?Product
    {
        $sql = "SELECT * FROM {$this->table} WHERE sku = ? LIMIT 1";

        $result = $this->db->query($sql, [$sku]);

        if ($result->numRows() === 0) {
            return null;
        }

        $product = $this->hydrateProduct($result->fetch());
        $product->setInventory($this->getInventory($product->getId()));
        $product->setImages($this->getImages($product->getId()));

        return $product;
    }

    public function findByCategory(ProductCategory $category, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT * FROM {$this->table}
            WHERE category = ?
            AND is_active = true
            ORDER BY name ASC
            LIMIT ? OFFSET ?";

        $result = $this->db->query($sql, [$category->getValue(), $limit, $offset]);

        $products = [];
        while ($row = $result->fetch()) {
            $products[] = $this->hydrateProduct($row);
        }

        return $products;
    }

    public function search(string $query, int $limit = 50): array
    {
        $sql = "SELECT * FROM {$this->table}
            WHERE (name LIKE ? OR description LIKE ?)
            AND is_active = true
            ORDER BY
                CASE WHEN name LIKE ? THEN 0 ELSE 1 END,
                name ASC
            LIMIT ?";

        $likeQuery = "%{$query}%";

        $result = $this->db->query($sql, [$likeQuery, $likeQuery, $likeQuery, $limit]);

        $products = [];
        while ($row = $result->fetch()) {
            $products[] = $this->hydrateProduct($row);
        }

        return $products;
    }

    public function save(Product $product): Product
    {
        if ($product->getId() === null) {
            return $this->insert($product);
        }

        return $this->update($product);
    }

    private function insert(Product $product): Product
    {
        $sql = "INSERT INTO {$this->table} (
            sku, name, description, category, price, currency,
            is_active, attributes, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $params = [
            $product->getSku(),
            $product->getName(),
            $product->getDescription(),
            $product->getCategory()->getValue(),
            $product->getPrice(),
            $product->getCurrency(),
            $product->isActive(),
            json_encode($product->getAttributes()),
        ];

        $this->db->query($sql, $params);

        $id = $this->db->getLastInsertId();
        $product->setId($id);

        $this->saveInventory($product);
        $this->saveImages($product);

        return $product;
    }

    private function update(Product $product): Product
    {
        $sql = "UPDATE {$this->table} SET
            name = ?,
            description = ?,
            category = ?,
            price = ?,
            is_active = ?,
            attributes = ?,
            updated_at = NOW()
        WHERE id = ?";

        $params = [
            $product->getName(),
            $product->getDescription(),
            $product->getCategory()->getValue(),
            $product->getPrice(),
            $product->isActive(),
            json_encode($product->getAttributes()),
            $product->getId(),
        ];

        $this->db->query($sql, $params);

        $this->saveInventory($product);
        $this->saveImages($product);

        return $product;
    }

    private function saveInventory(Product $product): void
    {
        $this->db->query(
            "DELETE FROM {$this->inventoryTable} WHERE product_id = ?",
            [$product->getId()]
        );

        $inventory = $product->getInventory();

        $sql = "INSERT INTO {$this->inventoryTable} (
            product_id, quantity, reserved_quantity, available_quantity,
            low_stock_threshold, updated_at
        ) VALUES (?, ?, ?, ?, ?, NOW())";

        $this->db->query($sql, [
            $product->getId(),
            $inventory->getQuantity(),
            $inventory->getReservedQuantity(),
            $inventory->getAvailableQuantity(),
            $inventory->getLowStockThreshold(),
        ]);
    }

    private function saveImages(Product $product): void
    {
        $this->db->query(
            "DELETE FROM {$this->imagesTable} WHERE product_id = ?",
            [$product->getId()]
        );

        foreach ($product->getImages() as $index => $imageUrl) {
            $sql = "INSERT INTO {$this->imagesTable} (
                product_id, image_url, display_order, updated_at
            ) VALUES (?, ?, ?, NOW())";

            $this->db->query($sql, [
                $product->getId(),
                $imageUrl,
                $index,
            ]);
        }
    }

    private function getInventory(string $productId): array
    {
        $sql = "SELECT * FROM {$this->inventoryTable} WHERE product_id = ?";

        $result = $this->db->query($sql, [$productId]);

        if ($result->numRows() === 0) {
            return [];
        }

        return $result->fetch();
    }

    private function getImages(string $productId): array
    {
        $sql = "SELECT image_url FROM {$this->imagesTable}
            WHERE product_id = ?
            ORDER BY display_order ASC";

        $result = $this->db->query($sql, [$productId]);

        $images = [];
        while ($row = $result->fetch()) {
            $images[] = $row['image_url'];
        }

        return $images;
    }

    private function hydrateProduct(array $row): Product
    {
        return new Product(
            id: $row['id'],
            sku: $row['sku'],
            name: $row['name'],
            description: $row['description'],
            category: ProductCategory::from($row['category']),
            price: (float) $row['price'],
            currency: $row['currency'],
            isActive: (bool) $row['is_active'],
            attributes: json_decode($row['attributes'], true),
            createdAt: new \DateTimeImmutable($row['created_at']),
        );
    }
}
