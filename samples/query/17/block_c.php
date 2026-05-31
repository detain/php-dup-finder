<?php
declare(strict_types=1);

namespace App\Analytics\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class SessionRepository
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
        $sortBy = $params['sort_by'] ?? 'started_at';
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        $allowedSorts = ['started_at', 'ended_at', 'user_id', 'page_count', 'duration'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'started_at';
        }
        
        $conditions = ['1=1'];
        $bindings = [];
        
        if (!empty($params['user_id'])) {
            $conditions[] = 'user_id = :user_id';
            $bindings[':user_id'] = (int)$params['user_id'];
        }
        
        if (!empty($params['session_type'])) {
            $conditions[] = 'session_type = :session_type';
            $bindings[':session_type'] = $params['session_type'];
        }
        
        if (!empty($params['device_type'])) {
            $conditions[] = 'device_type = :device_type';
            $bindings[':device_type'] = $params['device_type'];
        }
        
        if (!empty($params['browser'])) {
            $conditions[] = 'browser = :browser';
            $bindings[':browser'] = $params['browser'];
        }
        
        if (!empty($params['operating_system'])) {
            $conditions[] = 'operating_system = :operating_system';
            $bindings[':operating_system'] = $params['operating_system'];
        }
        
        if (!empty($params['country_code'])) {
            $conditions[] = 'country_code = :country_code';
            $bindings[':country_code'] = $params['country_code'];
        }
        
        if (!empty($params['started_after'])) {
            $conditions[] = 'started_at >= :started_after';
            $bindings[':started_after'] = $params['started_after'];
        }
        
        if (!empty($params['started_before'])) {
            $conditions[] = 'started_at <= :started_before';
            $bindings[':started_before'] = $params['started_before'];
        }
        
        if (!empty($params['ended_after'])) {
            $conditions[] = 'ended_at >= :ended_after';
            $bindings[':ended_after'] = $params['ended_after'];
        }
        
        if (!empty($params['ended_before'])) {
            $conditions[] = 'ended_at <= :ended_before';
            $bindings[':ended_before'] = $params['ended_before'];
        }
        
        if (!empty($params['min_page_count'])) {
            $conditions[] = 'page_count >= :min_page_count';
            $bindings[':min_page_count'] = (int)$params['min_page_count'];
        }
        
        if (!empty($params['max_page_count'])) {
            $conditions[] = 'page_count <= :max_page_count';
            $bindings[':max_page_count'] = (int)$params['max_page_count'];
        }
        
        if (!empty($params['min_duration'])) {
            $conditions[] = 'duration >= :min_duration';
            $bindings[':min_duration'] = (int)$params['min_duration'];
        }
        
        if (!empty($params['max_duration'])) {
            $conditions[] = 'duration <= :max_duration';
            $bindings[':max_duration'] = (int)$params['max_duration'];
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(session_id LIKE :search OR entry_page LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM sessions WHERE {$whereClause}");
        $this->bindValues($countStmt, $bindings);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT id, user_id, session_type, device_type, browser,
                   operating_system, country_code, page_count, duration,
                   entry_page, exit_page, started_at, ended_at
            FROM sessions
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
        
        $this->logger->info('Sessions pagination query executed', [
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

    public function startSession(array $sessionData): string
    {
        $sessionId = bin2hex(random_bytes(16));
        
        $sql = "INSERT INTO sessions (session_id, user_id, session_type, device_type,
                                      browser, operating_system, country_code,
                                      entry_page, started_at)
                VALUES (:session_id, :user_id, :session_type, :device_type,
                        :browser, :operating_system, :country_code,
                        :entry_page, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':session_id', $sessionId);
        $stmt->bindValue(':user_id', $sessionData['user_id'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':session_type', $sessionData['session_type'] ?? 'web');
        $stmt->bindValue(':device_type', $sessionData['device_type'] ?? 'desktop');
        $stmt->bindValue(':browser', $sessionData['browser'] ?? null);
        $stmt->bindValue(':operating_system', $sessionData['operating_system'] ?? null);
        $stmt->bindValue(':country_code', $sessionData['country_code'] ?? null);
        $stmt->bindValue(':entry_page', $sessionData['entry_page'] ?? '/');
        
        $stmt->execute();
        
        return $sessionId;
    }

    public function endSession(string $sessionId, string $exitPage, int $duration, int $pageCount): bool
    {
        $sql = "UPDATE sessions SET 
                    exit_page = :exit_page,
                    duration = :duration,
                    page_count = :page_count,
                    ended_at = NOW()
                WHERE session_id = :session_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':session_id', $sessionId);
        $stmt->bindValue(':exit_page', $exitPage);
        $stmt->bindValue(':duration', $duration, PDO::PARAM_INT);
        $stmt->bindValue(':page_count', $pageCount, PDO::PARAM_INT);
        
        return $stmt->execute();
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
