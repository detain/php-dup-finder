<?php
declare(strict_types=1);

namespace App\Analytics\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class PageViewRepository
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
        $sortBy = $params['sort_by'] ?? 'viewed_at';
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        $allowedSorts = ['viewed_at', 'page_url', 'user_id', 'duration_seconds'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'viewed_at';
        }
        
        $conditions = ['1=1'];
        $bindings = [];
        
        if (!empty($params['user_id'])) {
            $conditions[] = 'user_id = :user_id';
            $bindings[':user_id'] = (int)$params['user_id'];
        }
        
        if (!empty($params['session_id'])) {
            $conditions[] = 'session_id = :session_id';
            $bindings[':session_id'] = $params['session_id'];
        }
        
        if (!empty($params['page_url'])) {
            $conditions[] = 'page_url LIKE :page_url';
            $bindings[':page_url'] = '%' . $params['page_url'] . '%';
        }
        
        if (!empty($params['referrer_domain'])) {
            $conditions[] = 'referrer_domain = :referrer_domain';
            $bindings[':referrer_domain'] = $params['referrer_domain'];
        }
        
        if (!empty($params['device_type'])) {
            $conditions[] = 'device_type = :device_type';
            $bindings[':device_type'] = $params['device_type'];
        }
        
        if (!empty($params['browser'])) {
            $conditions[] = 'browser = :browser';
            $bindings[':browser'] = $params['browser'];
        }
        
        if (!empty($params['country_code'])) {
            $conditions[] = 'country_code = :country_code';
            $bindings[':country_code'] = $params['country_code'];
        }
        
        if (!empty($params['viewed_after'])) {
            $conditions[] = 'viewed_at >= :viewed_after';
            $bindings[':viewed_after'] = $params['viewed_after'];
        }
        
        if (!empty($params['viewed_before'])) {
            $conditions[] = 'viewed_at <= :viewed_before';
            $bindings[':viewed_before'] = $params['viewed_before'];
        }
        
        if (!empty($params['min_duration'])) {
            $conditions[] = 'duration_seconds >= :min_duration';
            $bindings[':min_duration'] = (int)$params['min_duration'];
        }
        
        if (!empty($params['max_duration'])) {
            $conditions[] = 'duration_seconds <= :max_duration';
            $bindings[':max_duration'] = (int)$params['max_duration'];
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(page_url LIKE :search OR session_id LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM page_views WHERE {$whereClause}");
        $this->bindValues($countStmt, $bindings);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT id, user_id, session_id, page_url, referrer_domain,
                   device_type, browser, country_code, duration_seconds,
                   viewed_at, created_at
            FROM page_views
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
        
        $this->logger->info('Page views pagination query executed', [
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

    public function recordView(array $viewData): int
    {
        $sql = "INSERT INTO page_views (user_id, session_id, page_url, referrer_domain,
                                       device_type, browser, country_code, duration_seconds,
                                       viewed_at, created_at)
                VALUES (:user_id, :session_id, :page_url, :referrer_domain,
                        :device_type, :browser, :country_code, :duration_seconds,
                        :viewed_at, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $viewData['user_id'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':session_id', $viewData['session_id']);
        $stmt->bindValue(':page_url', $viewData['page_url']);
        $stmt->bindValue(':referrer_domain', $viewData['referrer_domain'] ?? null);
        $stmt->bindValue(':device_type', $viewData['device_type'] ?? 'desktop');
        $stmt->bindValue(':browser', $viewData['browser'] ?? null);
        $stmt->bindValue(':country_code', $viewData['country_code'] ?? null);
        $stmt->bindValue(':duration_seconds', $viewData['duration_seconds'] ?? 0, PDO::PARAM_INT);
        $stmt->bindValue(':viewed_at', $viewData['viewed_at'] ?? date('Y-m-d H:i:s'));
        
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
