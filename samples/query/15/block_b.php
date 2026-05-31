<?php
declare(strict_types=1);

namespace App\Crm\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class DealRepository
{
    private PDO $db;
    private LoggerInterface $logger;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
    }

    public function findPaginated(array $params = []): array
    {
        $startTime = microtime(true);
        
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(10, (int)($params['per_page'] ?? 25)));
        $sortBy = $params['sort_by'] ?? 'created_at';
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        $allowedSorts = ['created_at', 'updated_at', 'expected_close_date', 'amount', 'name', 'stage'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }
        
        $conditions = ['deleted_at IS NULL'];
        $bindings = [];
        
        if (!empty($params['stage'])) {
            $conditions[] = 'stage = :stage';
            $bindings[':stage'] = $params['stage'];
        }
        
        if (!empty($params['status'])) {
            $conditions[] = 'status = :status';
            $bindings[':status'] = $params['status'];
        }
        
        if (!empty($params['contact_id'])) {
            $conditions[] = 'contact_id = :contact_id';
            $bindings[':contact_id'] = (int)$params['contact_id'];
        }
        
        if (!empty($params['company_id'])) {
            $conditions[] = 'company_id = :company_id';
            $bindings[':company_id'] = (int)$params['company_id'];
        }
        
        if (!empty($params['owner_id'])) {
            $conditions[] = 'owner_id = :owner_id';
            $bindings[':owner_id'] = (int)$params['owner_id'];
        }
        
        if (!empty($params['min_amount'])) {
            $conditions[] = 'amount >= :min_amount';
            $bindings[':min_amount'] = (float)$params['min_amount'];
        }
        
        if (!empty($params['max_amount'])) {
            $conditions[] = 'amount <= :max_amount';
            $bindings[':max_amount'] = (float)$params['max_amount'];
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(name LIKE :search OR description LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        if (!empty($params['created_after'])) {
            $conditions[] = 'created_at >= :created_after';
            $bindings[':created_after'] = $params['created_after'];
        }
        
        if (!empty($params['created_before'])) {
            $conditions[] = 'created_at <= :created_before';
            $bindings[':created_before'] = $params['created_before'];
        }
        
        if (!empty($params['expected_close_after'])) {
            $conditions[] = 'expected_close_date >= :expected_close_after';
            $bindings[':expected_close_after'] = $params['expected_close_after'];
        }
        
        if (!empty($params['expected_close_before'])) {
            $conditions[] = 'expected_close_date <= :expected_close_before';
            $bindings[':expected_close_before'] = $params['expected_close_before'];
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM deals WHERE {$whereClause}");
        foreach ($bindings as $key => $value) {
            if (is_int($key)) {
                $countStmt->bindValue($key + 1, $value);
            } else {
                $countStmt->bindValue($key, $value);
            }
        }
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT id, contact_id, company_id, owner_id, name, stage, status,
                   amount, currency, expected_close_date, closed_at,
                   created_at, updated_at
            FROM deals
            WHERE {$whereClause}
            ORDER BY {$sortBy} {$sortDir}
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $this->db->prepare($query);
        foreach ($bindings as $key => $value) {
            if (is_int($key)) {
                $stmt->bindValue($key + 1, $value);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->logger->info('Deals pagination query executed', [
            'filters' => $params,
            'total' => $totalRecords,
            'page' => $page,
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ]);
        
        return [
            'data' => $records,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_records' => $totalRecords,
                'total_pages' => (int)ceil($totalRecords / $perPage),
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
            ]
        ];
    }

    public function findById(int $id): ?array
    {
        $sql = "SELECT id, contact_id, company_id, owner_id, name, stage, status,
                       amount, currency, expected_close_date, closed_at,
                       probability, notes, created_at
                FROM deals 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function moveToStage(int $id, string $newStage, int $ownerId): bool
    {
        $sql = "UPDATE deals SET 
                    stage = :stage, 
                    owner_id = :owner_id,
                    stage_changed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':stage', $newStage);
        $stmt->bindValue(':owner_id', $ownerId, PDO::PARAM_INT);
        
        return $stmt->execute();
    }
}
