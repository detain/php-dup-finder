<?php
declare(strict_types=1);

namespace App\Notifications\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class EmailNotificationRepository
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
        
        $allowedSorts = ['created_at', 'sent_at', 'delivered_at', 'status', 'priority'];
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
        
        if (!empty($params['user_id'])) {
            $conditions[] = 'user_id = :user_id';
            $bindings[':user_id'] = (int)$params['user_id'];
        }
        
        if (!empty($params['recipient_email'])) {
            $conditions[] = 'recipient_email = :recipient_email';
            $bindings[':recipient_email'] = $params['recipient_email'];
        }
        
        if (!empty($params['template_id'])) {
            $conditions[] = 'template_id = :template_id';
            $bindings[':template_id'] = $params['template_id'];
        }
        
        if (!empty($params['category'])) {
            $conditions[] = 'category = :category';
            $bindings[':category'] = $params['category'];
        }
        
        if (isset($params['min_attempts']) && $params['min_attempts'] > 0) {
            $conditions[] = 'attempts >= :min_attempts';
            $bindings[':min_attempts'] = (int)$params['min_attempts'];
        }
        
        if (isset($params['max_attempts']) && $params['max_attempts'] > 0) {
            $conditions[] = 'attempts <= :max_attempts';
            $bindings[':max_attempts'] = (int)$params['max_attempts'];
        }
        
        if (!empty($params['created_after'])) {
            $conditions[] = 'created_at >= :created_after';
            $bindings[':created_after'] = $params['created_after'];
        }
        
        if (!empty($params['created_before'])) {
            $conditions[] = 'created_at <= :created_before';
            $bindings[':created_before'] = $params['created_before'];
        }
        
        if (!empty($params['sent_after'])) {
            $conditions[] = 'sent_at >= :sent_after';
            $bindings[':sent_after'] = $params['sent_after'];
        }
        
        if (!empty($params['sent_before'])) {
            $conditions[] = 'sent_at <= :sent_before';
            $bindings[':sent_before'] = $params['sent_before'];
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(subject LIKE :search OR recipient_email LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM email_notifications WHERE {$whereClause}");
        $this->bindValues($countStmt, $bindings);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT id, user_id, recipient_email, subject, template_id,
                   category, status, priority, attempts, sent_at,
                   delivered_at, opened_at, clicked_at, created_at
            FROM email_notifications
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
        
        $this->logger->info('Email notifications pagination query executed', [
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

    public function create(array $notificationData): int
    {
        $sql = "INSERT INTO email_notifications (user_id, recipient_email, subject,
                                                 template_id, category, status, priority,
                                                 created_at)
                VALUES (:user_id, :recipient_email, :subject,
                        :template_id, :category, 'pending', :priority, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $notificationData['user_id'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':recipient_email', $notificationData['recipient_email']);
        $stmt->bindValue(':subject', $notificationData['subject'] ?? '');
        $stmt->bindValue(':template_id', $notificationData['template_id'] ?? null);
        $stmt->bindValue(':category', $notificationData['category'] ?? 'general');
        $stmt->bindValue(':priority', $notificationData['priority'] ?? 5, PDO::PARAM_INT);
        
        $stmt->execute();
        
        return (int)$this->db->lastInsertId();
    }

    public function markAsSent(int $id, string $messageId): bool
    {
        $sql = "UPDATE email_notifications SET 
                    status = 'sent',
                    sent_at = NOW(),
                    message_id = :message_id,
                    attempts = attempts + 1,
                    updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':message_id', $messageId);
        
        return $stmt->execute();
    }

    public function markAsDelivered(int $id): bool
    {
        $sql = "UPDATE email_notifications SET 
                    status = 'delivered',
                    delivered_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        
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
