<?php
declare(strict_types=1);

namespace App\Subscription\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class SubscriptionRepository
{
    private PDO $db;
    private LoggerInterface $logger;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
    }

    public function findActiveSubscriptions(array $filters = [], int $page = 1, int $perPage = 25): array
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
        
        if (isset($filters['plan_id']) && $filters['plan_id'] > 0) {
            $conditions[] = 'plan_id = :plan_id';
            $params[':plan_id'] = $filters['plan_id'];
        }
        
        if (isset($filters['billing_cycle']) && $filters['billing_cycle'] !== '') {
            $conditions[] = 'billing_cycle = :billing_cycle';
            $params[':billing_cycle'] = $filters['billing_cycle'];
        }
        
        if (isset($filters['min_price']) && $filters['min_price'] > 0) {
            $conditions[] = 'price >= :min_price';
            $params[':min_price'] = $filters['min_price'];
        }
        
        if (isset($filters['max_price']) && $filters['max_price'] > 0) {
            $conditions[] = 'price <= :max_price';
            $params[':max_price'] = $filters['max_price'];
        }
        
        if (isset($filters['expires_after'])) {
            $conditions[] = 'expires_at >= :expires_after';
            $params[':expires_after'] = $filters['expires_after'];
        }
        
        if (isset($filters['expires_before'])) {
            $conditions[] = 'expires_at <= :expires_before';
            $params[':expires_before'] = $filters['expires_before'];
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
        $allowedSorts = ['created_at', 'expires_at', 'price', 'status', 'billing_cycle'];
        if (!in_array($sortField, $allowedSorts, true)) {
            $sortField = 'created_at';
        }
        if (!in_array($sortDir, ['ASC', 'DESC'], true)) {
            $sortDir = 'DESC';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countSql = "SELECT COUNT(*) FROM subscriptions WHERE {$whereClause}";
        $stmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();
        
        $sql = "SELECT id, customer_id, plan_id, status, billing_cycle,
                       price, currency, starts_at, expires_at,
                       cancelled_at, created_at
                FROM subscriptions 
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
        
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $elapsed = microtime(true) - $startTime;
        $this->logger->info('Active subscriptions query executed', [
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'elapsed_ms' => round($elapsed * 1000, 2)
        ]);
        
        return [
            'data' => $subscriptions,
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
        $sql = "SELECT id, customer_id, plan_id, status, billing_cycle,
                       price, currency, starts_at, expires_at,
                       trial_ends_at, cancelled_at, created_at
                FROM subscriptions 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function cancel(int $id, string $reason): bool
    {
        $sql = "UPDATE subscriptions SET 
                    status = 'cancelled', 
                    cancelled_at = NOW(),
                    cancellation_reason = :reason,
                    updated_at = NOW()
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':reason', $reason);
        
        $result = $stmt->execute();
        
        if ($result) {
            $this->logger->info('Subscription cancelled', [
                'subscription_id' => $id,
                'reason' => $reason
            ]);
        }
        
        return $result;
    }

    public function softDelete(int $id, int $deletedBy): bool
    {
        $sql = "UPDATE subscriptions SET deleted_at = NOW(), deleted_by = :deleted_by 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':deleted_by', $deletedBy, PDO::PARAM_INT);
        
        return $stmt->execute();
    }
}
