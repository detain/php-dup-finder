<?php
declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use App\Security\CurrentUser;
use PDO;
use DateTimeImmutable;

final class AuditLogRepository
{
    private PDO $db;
    private LoggerInterface $logger;
    private CurrentUser $currentUser;

    public function __construct(
        Connection $connection, 
        LoggerInterface $logger,
        CurrentUser $currentUser
    ) {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
        $this->currentUser = $currentUser;
    }

    public function getPaginatedAuditLogs(array $params = []): array
    {
        $startTime = microtime(true);
        
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(10, (int)($params['per_page'] ?? 25)));
        $sortBy = $params['sort_by'] ?? 'created_at';
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        $allowedSortColumns = ['created_at', 'action', 'entity_type', 'user_id', 'ip_address'];
        if (!in_array($sortBy, $allowedSortColumns, true)) {
            $sortBy = 'created_at';
        }
        
        $conditions = ['1=1'];
        $bindings = [];
        
        if (!empty($params['user_id'])) {
            $conditions[] = 'user_id = :user_id';
            $bindings[':user_id'] = (int)$params['user_id'];
        }
        
        if (!empty($params['action'])) {
            $conditions[] = 'action = :action';
            $bindings[':action'] = $params['action'];
        }
        
        if (!empty($params['entity_type'])) {
            $conditions[] = 'entity_type = :entity_type';
            $bindings[':entity_type'] = $params['entity_type'];
        }
        
        if (!empty($params['entity_id'])) {
            $conditions[] = 'entity_id = :entity_id';
            $bindings[':entity_id'] = (int)$params['entity_id'];
        }
        
        if (!empty($params['ip_address'])) {
            $conditions[] = 'ip_address = :ip_address';
            $bindings[':ip_address'] = $params['ip_address'];
        }
        
        if (!empty($params['date_from'])) {
            $conditions[] = 'created_at >= :date_from';
            $bindings[':date_from'] = $params['date_from'];
        }
        
        if (!empty($params['date_to'])) {
            $conditions[] = 'created_at <= :date_to';
            $bindings[':date_to'] = $params['date_to'];
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(entity_id LIKE :search OR metadata LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM audit_logs WHERE {$whereClause}
        ");
        foreach ($bindings as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT id, user_id, action, entity_type, entity_id, 
                   ip_address, metadata, created_at
            FROM audit_logs
            WHERE {$whereClause}
            ORDER BY {$sortBy} {$sortDir}
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $this->db->prepare($query);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->logger->info('Audit logs fetched', [
            'user' => $this->currentUser->getId(),
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

    public function createLogEntry(int $userId, string $action, string $entityType, 
                                   int $entityId, array $metadata = []): int
    {
        $sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, 
                                        ip_address, metadata, created_at)
                VALUES (:user_id, :action, :entity_type, :entity_id, 
                        :ip_address, :metadata, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':entity_type', $entityType);
        $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
        $stmt->bindValue(':ip_address', $this->currentUser->getIpAddress());
        $stmt->bindValue(':metadata', json_encode($metadata));
        
        $stmt->execute();
        
        return (int)$this->db->lastInsertId();
    }
}
