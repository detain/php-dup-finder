<?php
declare(strict_types=1);

namespace App\Analytics\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use App\Security\CurrentUser;
use PDO;

final class EventTrackRepository
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

    public function getPaginatedEvents(array $params = []): array
    {
        $startTime = microtime(true);
        
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(10, (int)($params['per_page'] ?? 25)));
        $sortBy = $params['sort_by'] ?? 'occurred_at';
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        $allowedSortColumns = ['occurred_at', 'event_type', 'user_id', 'session_id', 'source'];
        if (!in_array($sortBy, $allowedSortColumns, true)) {
            $sortBy = 'occurred_at';
        }
        
        $conditions = ['1=1'];
        $bindings = [];
        
        if (!empty($params['user_id'])) {
            $conditions[] = 'user_id = :user_id';
            $bindings[':user_id'] = (int)$params['user_id'];
        }
        
        if (!empty($params['event_type'])) {
            $conditions[] = 'event_type = :event_type';
            $bindings[':event_type'] = $params['event_type'];
        }
        
        if (!empty($params['session_id'])) {
            $conditions[] = 'session_id = :session_id';
            $bindings[':session_id'] = $params['session_id'];
        }
        
        if (!empty($params['source'])) {
            $conditions[] = 'source = :source';
            $bindings[':source'] = $params['source'];
        }
        
        if (!empty($params['event_name'])) {
            $conditions[] = 'event_name LIKE :event_name';
            $bindings[':event_name'] = '%' . $params['event_name'] . '%';
        }
        
        if (!empty($params['date_from'])) {
            $conditions[] = 'occurred_at >= :date_from';
            $bindings[':date_from'] = $params['date_from'];
        }
        
        if (!empty($params['date_to'])) {
            $conditions[] = 'occurred_at <= :date_to';
            $bindings[':date_to'] = $params['date_to'];
        }
        
        if (!empty($params['properties'])) {
            $conditions[] = 'properties->"$.' . $params['properties_key'] . '" = :prop_value';
            $bindings[':prop_value'] = $params['properties'];
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(event_name LIKE :search OR properties LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM analytics_events WHERE {$whereClause}
        ");
        foreach ($bindings as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT id, user_id, session_id, event_type, event_name,
                   source, properties, occurred_at
            FROM analytics_events
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
        
        $this->logger->info('Analytics events fetched', [
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

    public function trackEvent(int $userId, string $eventType, string $eventName,
                              string $source, array $properties = []): int
    {
        $sql = "INSERT INTO analytics_events (user_id, session_id, event_type, 
                                             event_name, source, properties, occurred_at)
                VALUES (:user_id, :session_id, :event_type, :event_name, 
                        :source, :properties, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':session_id', $this->currentUser->getSessionId(), PDO::PARAM_INT);
        $stmt->bindValue(':event_type', $eventType);
        $stmt->bindValue(':event_name', $eventName);
        $stmt->bindValue(':source', $source);
        $stmt->bindValue(':properties', json_encode($properties));
        
        $stmt->execute();
        
        return (int)$this->db->lastInsertId();
    }
}
