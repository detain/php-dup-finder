<?php
declare(strict_types=1);

namespace App\Shipping\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class ShipmentRepository
{
    private PDO $db;
    private LoggerInterface $logger;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
    }

    public function findActiveShipments(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $startTime = microtime(true);
        
        $conditions = ['deleted_at IS NULL'];
        $params = [];
        
        if (isset($filters['status']) && $filters['status'] !== '') {
            $conditions[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }
        
        if (isset($filters['order_id']) && $filters['order_id'] > 0) {
            $conditions[] = 'order_id = :order_id';
            $params[':order_id'] = $filters['order_id'];
        }
        
        if (isset($filters['tracking_number']) && $filters['tracking_number'] !== '') {
            $conditions[] = 'tracking_number LIKE :tracking_number';
            $params[':tracking_number'] = '%' . $filters['tracking_number'] . '%';
        }
        
        if (isset($filters['carrier']) && $filters['carrier'] !== '') {
            $conditions[] = 'carrier = :carrier';
            $params[':carrier'] = $filters['carrier'];
        }
        
        if (isset($filters['shipping_method']) && $filters['shipping_method'] !== '') {
            $conditions[] = 'shipping_method = :shipping_method';
            $params[':shipping_method'] = $filters['shipping_method'];
        }
        
        if (isset($filters['delivered_after'])) {
            $conditions[] = 'delivered_at >= :delivered_after';
            $params[':delivered_after'] = $filters['delivered_after'];
        }
        
        if (isset($filters['delivered_before'])) {
            $conditions[] = 'delivered_at <= :delivered_before';
            $params[':delivered_before'] = $filters['delivered_before'];
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
        $allowedSorts = ['created_at', 'delivered_at', 'status', 'tracking_number', 'carrier'];
        if (!in_array($sortField, $allowedSorts, true)) {
            $sortField = 'created_at';
        }
        if (!in_array($sortDir, ['ASC', 'DESC'], true)) {
            $sortDir = 'DESC';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countSql = "SELECT COUNT(*) FROM shipments WHERE {$whereClause}";
        $stmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();
        
        $sql = "SELECT id, order_id, tracking_number, carrier, shipping_method,
                       status, shipped_at, delivered_at, created_at
                FROM shipments 
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
        
        $shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $elapsed = microtime(true) - $startTime;
        $this->logger->info('Active shipments query executed', [
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'elapsed_ms' => round($elapsed * 1000, 2)
        ]);
        
        return [
            'data' => $shipments,
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
        $sql = "SELECT id, order_id, tracking_number, carrier, shipping_method,
                       status, weight, dimensions, shipped_at, delivered_at,
                       shipping_address, created_at
                FROM shipments 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function markAsDelivered(int $id, string $signedBy): bool
    {
        $sql = "UPDATE shipments SET 
                    status = 'delivered', 
                    delivered_at = NOW(),
                    signed_by = :signed_by,
                    updated_at = NOW()
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':signed_by', $signedBy);
        
        $result = $stmt->execute();
        
        if ($result) {
            $this->logger->info('Shipment marked as delivered', [
                'shipment_id' => $id,
                'signed_by' => $signedBy
            ]);
        }
        
        return $result;
    }

    public function softDelete(int $id, int $deletedBy): bool
    {
        $sql = "UPDATE shipments SET deleted_at = NOW(), deleted_by = :deleted_by 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':deleted_by', $deletedBy, PDO::PARAM_INT);
        
        return $stmt->execute();
    }
}
