<?php
declare(strict_types=1);

namespace App\Crm\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class ActivityRepository
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
        $sortBy = $params['sort_by'] ?? 'occurred_at';
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        $allowedSorts = ['occurred_at', 'created_at', 'type', 'subject'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'occurred_at';
        }
        
        $conditions = ['deleted_at IS NULL'];
        $bindings = [];
        
        if (!empty($params['type'])) {
            $conditions[] = 'type = :type';
            $bindings[':type'] = $params['type'];
        }
        
        if (!empty($params['contact_id'])) {
            $conditions[] = 'contact_id = :contact_id';
            $bindings[':contact_id'] = (int)$params['contact_id'];
        }
        
        if (!empty($params['deal_id'])) {
            $conditions[] = 'deal_id = :deal_id';
            $bindings[':deal_id'] = (int)$params['deal_id'];
        }
        
        if (!empty($params['company_id'])) {
            $conditions[] = 'company_id = :company_id';
            $bindings[':company_id'] = (int)$params['company_id'];
        }
        
        if (!empty($params['owner_id'])) {
            $conditions[] = 'owner_id = :owner_id';
            $bindings[':owner_id'] = (int)$params['owner_id'];
        }
        
        if (!empty($params['direction'])) {
            $conditions[] = 'direction = :direction';
            $bindings[':direction'] = $params['direction'];
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(subject LIKE :search OR notes LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        if (!empty($params['occurred_after'])) {
            $conditions[] = 'occurred_at >= :occurred_after';
            $bindings[':occurred_after'] = $params['occurred_after'];
        }
        
        if (!empty($params['occurred_before'])) {
            $conditions[] = 'occurred_at <= :occurred_before';
            $bindings[':occurred_before'] = $params['occurred_before'];
        }
        
        if (!empty($params['created_after'])) {
            $conditions[] = 'created_at >= :created_after';
            $bindings[':created_after'] = $params['created_after'];
        }
        
        if (!empty($params['created_before'])) {
            $conditions[] = 'created_at <= :created_before';
            $bindings[':created_before'] = $params['created_before'];
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM activities WHERE {$whereClause}");
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
            SELECT id, contact_id, deal_id, company_id, owner_id, type,
                   subject, direction, occurred_at, duration_minutes,
                   created_at
            FROM activities
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
        
        $this->logger->info('Activities pagination query executed', [
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
        $sql = "SELECT id, contact_id, deal_id, company_id, owner_id, type,
                       subject, direction, occurred_at, duration_minutes,
                       notes, created_at
                FROM activities 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function logActivity(array $activityData): int
    {
        $sql = "INSERT INTO activities (contact_id, deal_id, company_id, owner_id,
                                        type, subject, direction, occurred_at,
                                        duration_minutes, notes, created_at)
                VALUES (:contact_id, :deal_id, :company_id, :owner_id,
                        :type, :subject, :direction, :occurred_at,
                        :duration_minutes, :notes, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':contact_id', $activityData['contact_id'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':deal_id', $activityData['deal_id'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $activityData['company_id'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':owner_id', $activityData['owner_id'], PDO::PARAM_INT);
        $stmt->bindValue(':type', $activityData['type']);
        $stmt->bindValue(':subject', $activityData['subject']);
        $stmt->bindValue(':direction', $activityData['direction'] ?? 'outbound');
        $stmt->bindValue(':occurred_at', $activityData['occurred_at'] ?? date('Y-m-d H:i:s'));
        $stmt->bindValue(':duration_minutes', $activityData['duration_minutes'] ?? 0, PDO::PARAM_INT);
        $stmt->bindValue(':notes', $activityData['notes'] ?? null);
        
        $stmt->execute();
        
        return (int)$this->db->lastInsertId();
    }
}
