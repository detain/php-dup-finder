<?php
declare(strict_types=1);

namespace App\Crm\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class ContactRepository
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
        
        $allowedSorts = ['created_at', 'updated_at', 'last_contacted_at', 'name', 'email'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }
        
        $conditions = ['deleted_at IS NULL'];
        $bindings = [];
        
        if (!empty($params['status'])) {
            $conditions[] = 'status = :status';
            $bindings[':status'] = $params['status'];
        }
        
        if (!empty($params['company_id'])) {
            $conditions[] = 'company_id = :company_id';
            $bindings[':company_id'] = (int)$params['company_id'];
        }
        
        if (!empty($params['source'])) {
            $conditions[] = 'source = :source';
            $bindings[':source'] = $params['source'];
        }
        
        if (!empty($params['tags'])) {
            $tagList = is_array($params['tags']) ? $params['tags'] : explode(',', $params['tags']);
            $placeholders = implode(',', array_fill(0, count($tagList), '?'));
            $conditions[] = "id IN (SELECT contact_id FROM contact_tags WHERE tag IN ({$placeholders}))";
            foreach ($tagList as $i => $tag) {
                $bindings[$i] = trim($tag);
            }
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(name LIKE :search OR email LIKE :search OR phone LIKE :search)';
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
        
        if (!empty($params['last_contacted_after'])) {
            $conditions[] = 'last_contacted_at >= :last_contacted_after';
            $bindings[':last_contacted_after'] = $params['last_contacted_after'];
        }
        
        if (!empty($params['last_contacted_before'])) {
            $conditions[] = 'last_contacted_at <= :last_contacted_before';
            $bindings[':last_contacted_before'] = $params['last_contacted_before'];
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM contacts WHERE {$whereClause}");
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
            SELECT id, company_id, name, email, phone, status, source,
                   last_contacted_at, created_at, updated_at
            FROM contacts
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
        
        $this->logger->info('Contacts pagination query executed', [
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
        $sql = "SELECT id, company_id, name, email, phone, status, source,
                       address, notes, last_contacted_at, created_at
                FROM contacts 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function updateLastContacted(int $id): bool
    {
        $sql = "UPDATE contacts SET last_contacted_at = NOW(), updated_at = NOW() 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        
        return $stmt->execute();
    }
}
