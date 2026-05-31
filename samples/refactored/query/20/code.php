<?php
declare(strict_types=1);

namespace App\RateLimiting\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

abstract class AbstractRateLimitRepository
{
    protected PDO $db;
    protected LoggerInterface $logger;

    abstract protected function getTable(): string;
    abstract protected function getSelectColumns(): string;
    abstract protected function getIdentifierField(): string;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
    }

    protected function getAllowedSorts(): array
    {
        return ['window_start', 'hit_count', 'limit_type'];
    }

    protected function buildConditions(array $params): array
    {
        $conditions = ['1=1'];
        $bindings = [];
        
        $this->addIdentifierCondition($params, $conditions, $bindings);
        $this->addLimitTypeCondition($params, $conditions, $bindings);
        $this->addTierCondition($params, $conditions, $bindings);
        $this->addExceededCondition($params, $conditions, $bindings);
        $this->addHitCountConditions($params, $conditions, $bindings);
        $this->addWindowConditions($params, $conditions, $bindings);
        $this->addTimestampConditions($params, $conditions, $bindings);
        $this->addSearchCondition($params, $conditions, $bindings);
        
        return [$conditions, $bindings];
    }

    protected function addIdentifierCondition(array $params, array &$conditions, array &$bindings): void
    {
        $field = $this->getIdentifierField();
        if (!empty($params[$field])) {
            $conditions[] = "{$field} = :{$field}";
            $bindings[":{$field}"] = is_int($params[$field]) 
                ? (int)$params[$field] 
                : $params[$field];
        }
    }

    protected function addLimitTypeCondition(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['limit_type'])) {
            $conditions[] = 'limit_type = :limit_type';
            $bindings[':limit_type'] = $params['limit_type'];
        }
    }

    protected function addTierCondition(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['tier']) || !empty($params['account_tier'])) {
            $field = !empty($params['tier']) ? 'tier' : 'account_tier';
            $conditions[] = "{$field} = :{$field}";
            $bindings[":{$field}"] = $params[$field];
        }
    }

    protected function addExceededCondition(array $params, array &$conditions, array &$bindings): void
    {
        if (isset($params['is_exceeded'])) {
            $conditions[] = $params['is_exceeded'] 
                ? 'hit_count >= limit_count' 
                : 'hit_count < limit_count';
        }
    }

    protected function addHitCountConditions(array $params, array &$conditions, array &$bindings): void
    {
        if (isset($params['min_hit_count']) && $params['min_hit_count'] > 0) {
            $conditions[] = 'hit_count >= :min_hit_count';
            $bindings[':min_hit_count'] = (int)$params['min_hit_count'];
        }
        if (isset($params['max_hit_count']) && $params['max_hit_count'] > 0) {
            $conditions[] = 'hit_count <= :max_hit_count';
            $bindings[':max_hit_count'] = (int)$params['max_hit_count'];
        }
    }

    protected function addWindowConditions(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['window_start_after'])) {
            $conditions[] = 'window_start >= :window_start_after';
            $bindings[':window_start_after'] = $params['window_start_after'];
        }
        if (!empty($params['window_start_before'])) {
            $conditions[] = 'window_start <= :window_start_before';
            $bindings[':window_start_before'] = $params['window_start_before'];
        }
        if (!empty($params['window_duration'])) {
            $conditions[] = 'window_duration = :window_duration';
            $bindings[':window_duration'] = (int)$params['window_duration'];
        }
    }

    protected function addTimestampConditions(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['created_after'])) {
            $conditions[] = 'created_at >= :created_after';
            $bindings[':created_after'] = $params['created_after'];
        }
        if (!empty($params['created_before'])) {
            $conditions[] = 'created_at <= :created_before';
            $bindings[':created_before'] = $params['created_before'];
        }
    }

    protected function addSearchCondition(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['search'])) {
            $conditions[] = '(identifier LIKE :search OR endpoint LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
    }

    public function findPaginated(array $params = []): array
    {
        $startTime = microtime(true);
        
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(10, (int)($params['per_page'] ?? 25)));
        $sortBy = $params['sort_by'] ?? $this->getAllowedSorts()[0];
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        if (!in_array($sortBy, $this->getAllowedSorts(), true)) {
            $sortBy = $this->getAllowedSorts()[0];
        }
        
        [$conditions, $bindings] = $this->buildConditions($params);
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->getTable()} WHERE {$whereClause}");
        $this->bindValues($countStmt, $bindings);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT {$this->getSelectColumns()}
            FROM {$this->getTable()}
            WHERE {$whereClause}
            ORDER BY {$sortBy} {$sortDir}
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $this->db->prepare($query);
        $this->bindValues($stmt, $bindings);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->logger->info('Rate limits pagination query executed', [
            'table' => $this->getTable(),
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

    public function incrementHitCount(int $id): bool
    {
        $sql = "UPDATE {$this->getTable()} SET 
                    hit_count = hit_count + 1,
                    updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    public function findActiveRateLimit(string $identifier, string $limitType, int $windowDuration): ?array
    {
        $identifierField = $this->getIdentifierField();
        
        $sql = "SELECT {$this->getSelectColumns()}
                FROM {$this->getTable()} 
                WHERE {$identifierField} = :identifier 
                  AND limit_type = :limit_type 
                  AND window_duration = :window_duration
                  AND window_start >= DATE_SUB(NOW(), INTERVAL :window_duration SECOND)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':identifier', $identifier);
        $stmt->bindValue(':limit_type', $limitType);
        $stmt->bindValue(':window_duration', $windowDuration, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    private function bindValues(\PDOStatement $stmt, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            if (is_int($key)) {
                $stmt->bindValue($key + 1, $value);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
    }
}
