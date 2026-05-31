<?php
declare(strict_types=1);

namespace App\Order\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use App\Cache\CacheManager;
use PDO;

final class OrderRepository
{
    private PDO $db;
    private LoggerInterface $logger;
    private CacheManager $cache;

    public function __construct(Connection $connection, LoggerInterface $logger, CacheManager $cache)
    {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
        $this->cache = $cache;
    }

    /**
     * Find all active orders with optional filters and pagination.
     * Only returns orders where deleted_at IS NULL.
     */
    public function findActiveOrders(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $startTime = microtime(true);
        
        $conditions = ['deleted_at IS NULL'];
        $params = [];
        
        if (isset($filters['status']) && $filters['status'] !== '') {
            $conditions[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }
        
        if (isset($filters['customer_id']) && $filters['customer_id'] > 0) {
            $conditions[] = 'customer_id = :customer_id';
            $params[':customer_id'] = $filters['customer_id'];
        }
        
        if (isset($filters['payment_method']) && $filters['payment_method'] !== '') {
            $conditions[] = 'payment_method = :payment_method';
            $params[':payment_method'] = $filters['payment_method'];
        }
        
        if (isset($filters['min_total']) && $filters['min_total'] > 0) {
            $conditions[] = 'total_amount >= :min_total';
            $params[':min_total'] = $filters['min_total'];
        }
        
        if (isset($filters['max_total']) && $filters['max_total'] > 0) {
            $conditions[] = 'total_amount <= :max_total';
            $params[':max_total'] = $filters['max_total'];
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
        $allowedSorts = ['created_at', 'total_amount', 'status', 'updated_at'];
        if (!in_array($sortField, $allowedSorts, true)) {
            $sortField = 'created_at';
        }
        if (!in_array($sortDir, ['ASC', 'DESC'], true)) {
            $sortDir = 'DESC';
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        $offset = ($page - 1) * $perPage;
        
        $countSql = "SELECT COUNT(*) FROM orders WHERE {$whereClause}";
        $stmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();
        
        $sql = "SELECT id, customer_id, status, payment_method, total_amount, 
                       currency, created_at, updated_at
                FROM orders 
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
        
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $elapsed = microtime(true) - $startTime;
        $this->logger->info('Active orders query executed', [
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'elapsed_ms' => round($elapsed * 1000, 2)
        ]);
        
        return [
            'data' => $orders,
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
        $sql = "SELECT id, customer_id, status, payment_method, total_amount, 
                       currency, shipping_address, billing_address, created_at
                FROM orders 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function softDelete(int $id, int $deletedBy): bool
    {
        $sql = "UPDATE orders SET deleted_at = NOW(), deleted_by = :deleted_by WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':deleted_by', $deletedBy, PDO::PARAM_INT);
        
        $result = $stmt->execute();
        
        if ($result) {
            $this->cache->invalidatePattern('orders:*');
            $this->logger->warning('Order soft deleted', ['order_id' => $id, 'deleted_by' => $deletedBy]);
        }
        
        return $result;
    }
}
