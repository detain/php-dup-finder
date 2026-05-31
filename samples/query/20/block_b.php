<?php
declare(strict_types=1);

namespace App\RateLimiting\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class UserRateLimitRepository
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
        $sortBy = $params['sort_by'] ?? 'window_start';
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        $allowedSorts = ['window_start', 'hit_count', 'user_id', 'limit_type'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'window_start';
        }
        
        $conditions = ['1=1'];
        $bindings = [];
        
        if (!empty($params['user_id'])) {
            $conditions[] = 'user_id = :user_id';
            $bindings[':user_id'] = (int)$params['user_id'];
        }
        
        if (!empty($params['limit_type'])) {
            $conditions[] = 'limit_type = :limit_type';
            $bindings[':limit_type'] = $params['limit_type'];
        }
        
        if (!empty($params['account_tier'])) {
            $conditions[] = 'account_tier = :account_tier';
            $bindings[':account_tier'] = $params['account_tier'];
        }
        
        if (!empty($params['is_exceeded'])) {
            $conditions[] = $params['is_exceeded'] ? 'hit_count >= limit_count' : 'hit_count < limit_count';
        }
        
        if (isset($params['min_hit_count']) && $params['min_hit_count'] > 0) {
            $conditions[] = 'hit_count >= :min_hit_count';
            $bindings[':min_hit_count'] = (int)$params['min_hit_count'];
        }
        
        if (isset($params['max_hit_count']) && $params['max_hit_count'] > 0) {
            $conditions[] = 'hit_count <= :max_hit_count';
            $bindings[':max_hit_count'] = (int)$params['max_hit_count'];
        }
        
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
        
        if (!empty($params['created_after'])) {
            $conditions[] = 'created_at >= :created_after';
            $bindings[':created_after'] = $params['created_after'];
        }
        
        if (!empty($params['created_before'])) {
            $conditions[] = 'created_at <= :created_before';
            $bindings[':created_before'] = $params['created_before'];
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(user_id LIKE :search OR ip_address LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM user_rate_limits WHERE {$whereClause}");
        $this->bindValues($countStmt, $bindings);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT id, user_id, limit_type, account_tier, ip_address,
                   hit_count, limit_count, window_start, window_duration,
                   created_at, updated_at
            FROM user_rate_limits
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
        
        $this->logger->info('User rate limits pagination query executed', [
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

    public function findByUserAndType(int $userId, string $limitType, int $windowDuration): ?array
    {
        $sql = "SELECT id, user_id, limit_type, account_tier, ip_address,
                       hit_count, limit_count, window_start, window_duration
                FROM user_rate_limits 
                WHERE user_id = :user_id 
                  AND limit_type = :limit_type 
                  AND window_duration = :window_duration
                  AND window_start >= DATE_SUB(NOW(), INTERVAL :window_duration SECOND)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit_type', $limitType);
        $stmt->bindValue(':window_duration', $windowDuration, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function incrementHitCount(int $id): bool
    {
        $sql = "UPDATE user_rate_limits SET 
                    hit_count = hit_count + 1,
                    updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    public function createRateLimitEntry(array $entryData): int
    {
        $sql = "INSERT INTO user_rate_limits (user_id, limit_type, account_tier, ip_address,
                                             hit_count, limit_count, window_start,
                                             window_duration, created_at)
                VALUES (:user_id, :limit_type, :account_tier, :ip_address,
                        0, :limit_count, :window_start,
                        :window_duration, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $entryData['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(':limit_type', $entryData['limit_type']);
        $stmt->bindValue(':account_tier', $entryData['account_tier'] ?? 'free');
        $stmt->bindValue(':ip_address', $entryData['ip_address'] ?? null);
        $stmt->bindValue(':limit_count', $entryData['limit_count'], PDO::PARAM_INT);
        $stmt->bindValue(':window_start', $entryData['window_start'] ?? date('Y-m-d H:i:s'));
        $stmt->bindValue(':window_duration', $entryData['window_duration'], PDO::PARAM_INT);
        
        $stmt->execute();
        
        return (int)$this->db->lastInsertId();
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
