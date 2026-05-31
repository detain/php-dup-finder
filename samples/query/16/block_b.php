<?php
declare(strict_types=1);

namespace App\Queue\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class WebhookJobRepository
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
        
        $allowedSorts = ['created_at', 'scheduled_at', 'attempts', 'priority', 'status'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }
        
        $conditions = ['1=1'];
        $bindings = [];
        
        if (!empty($params['status'])) {
            $conditions[] = 'status = :status';
            $bindings[':status'] = $params['status'];
        }
        
        if (!empty($params['priority'])) {
            $conditions[] = 'priority = :priority';
            $bindings[':priority'] = (int)$params['priority'];
        }
        
        if (isset($params['min_attempts']) && $params['min_attempts'] > 0) {
            $conditions[] = 'attempts >= :min_attempts';
            $bindings[':min_attempts'] = (int)$params['min_attempts'];
        }
        
        if (isset($params['max_attempts']) && $params['max_attempts'] > 0) {
            $conditions[] = 'attempts <= :max_attempts';
            $bindings[':max_attempts'] = (int)$params['max_attempts'];
        }
        
        if (!empty($params['webhook_type'])) {
            $conditions[] = 'webhook_type = :webhook_type';
            $bindings[':webhook_type'] = $params['webhook_type'];
        }
        
        if (!empty($params['target_url'])) {
            $conditions[] = 'target_url LIKE :target_url';
            $bindings[':target_url'] = '%' . $params['target_url'] . '%';
        }
        
        if (!empty($params['event_type'])) {
            $conditions[] = 'event_type = :event_type';
            $bindings[':event_type'] = $params['event_type'];
        }
        
        if (!empty($params['scheduled_after'])) {
            $conditions[] = 'scheduled_at >= :scheduled_after';
            $bindings[':scheduled_after'] = $params['scheduled_after'];
        }
        
        if (!empty($params['scheduled_before'])) {
            $conditions[] = 'scheduled_at <= :scheduled_before';
            $bindings[':scheduled_before'] = $params['scheduled_before'];
        }
        
        if (!empty($params['created_after'])) {
            $conditions[] = 'created_at >= :created_after';
            $bindings[':created_after'] = $params['created_after'];
        }
        
        if (!empty($params['created_before'])) {
            $conditions[] = 'created_at <= :created_before';
            $bindings[':created_before'] = $params['created_before'];
        }
        
        if (isset($params['has_error']) && $params['has_error']) {
            $conditions[] = 'last_error IS NOT NULL';
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(job_id LIKE :search OR payload LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM webhook_jobs WHERE {$whereClause}");
        $this->bindValues($countStmt, $bindings);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT id, job_id, webhook_type, event_type, status, priority,
                   attempts, max_attempts, target_url, scheduled_at,
                   started_at, completed_at, last_error, created_at
            FROM webhook_jobs
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
        
        $this->logger->info('Webhook jobs pagination query executed', [
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
        $sql = "SELECT id, job_id, webhook_type, event_type, status, priority,
                       attempts, max_attempts, target_url, payload, headers,
                       scheduled_at, started_at, completed_at, last_error, created_at
                FROM webhook_jobs 
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function scheduleJob(array $jobData): int
    {
        $sql = "INSERT INTO webhook_jobs (job_id, webhook_type, event_type, status,
                                         priority, attempts, max_attempts, target_url,
                                         payload, headers, scheduled_at, created_at)
                VALUES (:job_id, :webhook_type, :event_type, 'pending',
                        :priority, 0, :max_attempts, :target_url,
                        :payload, :headers, :scheduled_at, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':job_id', $jobData['job_id']);
        $stmt->bindValue(':webhook_type', $jobData['webhook_type']);
        $stmt->bindValue(':event_type', $jobData['event_type']);
        $stmt->bindValue(':priority', $jobData['priority'] ?? 5, PDO::PARAM_INT);
        $stmt->bindValue(':max_attempts', $jobData['max_attempts'] ?? 3, PDO::PARAM_INT);
        $stmt->bindValue(':target_url', $jobData['target_url']);
        $stmt->bindValue(':payload', json_encode($jobData['payload']));
        $stmt->bindValue(':headers', json_encode($jobData['headers'] ?? []));
        $stmt->bindValue(':scheduled_at', $jobData['scheduled_at'] ?? date('Y-m-d H:i:s'));
        
        $stmt->execute();
        
        return (int)$this->db->lastInsertId();
    }

    public function markFailed(int $id, string $error): bool
    {
        $sql = "UPDATE webhook_jobs SET 
                    status = 'failed',
                    last_error = :error,
                    attempts = attempts + 1,
                    updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':error', $error);
        
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
