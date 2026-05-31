<?php
declare(strict_types=1);

namespace App\Catalog\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class ProductRepository
{
    private PDO $db;
    private LoggerInterface $logger;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
    }

    /**
     * Find all active products with optional filters and pagination.
     * Only returns products where deleted_at IS NULL.
     */
    public function findActiveProducts(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $startTime = microtime(true);
        
        $conditions = ['deleted_at IS NULL'];
        $params = [];
        
        if (isset($filters['category_id']) && $filters['category_id'] > 0) {
            $conditions[] = 'category_id = :category_id';
            $params[':category_id'] = $filters['category_id'];
        }
        
        if (isset($filters['status']) && $filters['status'] !== '') {
            $conditions[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }
        
        if (isset($filters['visibility']) && $filters['visibility'] !== '') {
            $conditions[] = 'visibility = :visibility';
            $params[':visibility'] = $filters['visibility'];
        }
        
        if (isset($filters['min_price']) && $filters['min_price'] > 0) {
            $conditions[] = 'price >= :min_price';
            $params[':min_price'] = $filters['min_price'];
        }
        
        if (isset($filters['max_price']) && $filters['max_price'] > 0) {
            $conditions[] = 'price <= :max_price';
            $params[':max_price'] = $filters['max_price'];
        }
        
        if (isset($filters['in_stock'])) {
            $conditions[] = $filters['in_stock'] ? 'stock_quantity > 0' : 'stock_quantity = 0';
        }
        
        if (isset($filters['search']) && $filters['search'] !== '') {
            $conditions[] = '(name LIKE :search OR sku LIKE :search OR description LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        if (isset($filters['created_after'])) {
            $conditions[] = 'created_at >= :created_after';
            $params[':created_after'] = $filters['created_after'];
        }
        
        if (isset($filters['created_before'])) {
            $conditions[] = 'created_at <= :created_before';
            $params[':created_before'] = $filters['created_before'];
        }
        
        $sortField = $filters['sort_by'] ?? 'created_at';
        $sortDir = strtoupper($filters['sort_dir'] ?? 'DESC');
        $allowedSorts = ['created_at', 'price', 'name', 'stock_quantity', 'updated_at'];
        if (!in_array($sortField, $allowedSorts, true)) {
            $sortField = 'created_at';
        }
        if (!in_array($sortDir, ['ASC', 'DESC'], true)) {
            $sortDir = 'DESC';
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        $offset = ($page - 1) * $perPage;
        
        $countSql = "SELECT COUNT(*) FROM products WHERE {$whereClause}";
        $stmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();
        
        $sql = "SELECT id, sku, name, category_id, status, price, stock_quantity, 
                       visibility, created_at, updated_at
                FROM products 
                WHERE {$whereClause}
                ORDER BY {$sortField} {$sortDir}
                LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $elapsed = microtime(true) - $startTime;
        $this->logger->info('Active products query executed', [
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'elapsed_ms' => round($elapsed * 1000, 2)
        ]);
        
        return [
            'data' => $products,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage)
            ]
        ];
    }

    public function findById(int $id): ?array
    {
        $sql = "SELECT id, sku, name, category_id, status, price, cost_price,
                       stock_quantity, visibility, description, created_at
                FROM products 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findBySku(string $sku): ?array
    {
        $sql = "SELECT id, sku, name, category_id, status, price, stock_quantity
                FROM products 
                WHERE sku = :sku AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':sku', $sku);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function softDelete(int $id, int $deletedBy): bool
    {
        $sql = "UPDATE products SET deleted_at = NOW(), deleted_by = :deleted_by WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':deleted_by', $deletedBy, PDO::PARAM_INT);
        
        $result = $stmt->execute();
        
        if ($result) {
            $this->logger->warning('Product soft deleted', ['product_id' => $id, 'deleted_by' => $deletedBy]);
        }
        
        return $result;
    }
}
