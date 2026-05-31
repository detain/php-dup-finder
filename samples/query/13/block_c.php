<?php
declare(strict_types=1);

namespace App\Support\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use App\Cache\CacheManager;
use PDO;

final class TicketSearchRepository
{
    private PDO $db;
    private LoggerInterface $logger;
    private CacheManager $cache;

    private const DEFAULT_MIN_SCORE = 0.2;
    private const DEFAULT_MAX_RESULTS = 50;

    public function __construct(
        Connection $connection,
        LoggerInterface $logger,
        CacheManager $cache
    ) {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
        $this->cache = $cache;
    }

    public function search(array $criteria): array
    {
        $startTime = microtime(true);
        
        $query = trim($criteria['q'] ?? '');
        if (strlen($query) < 2) {
            return [
                'success' => false,
                'error' => 'Search query must be at least 2 characters',
                'data' => [],
                'meta' => []
            ];
        }
        
        $page = max(1, (int)($criteria['page'] ?? 1));
        $perPage = min(100, max(10, (int)($criteria['per_page'] ?? 25)));
        $minScore = (float)($criteria['min_score'] ?? self::DEFAULT_MIN_SCORE);
        $maxResults = min(500, max(10, (int)($criteria['max_results'] ?? self::DEFAULT_MAX_RESULTS)));
        
        $customerId = !empty($criteria['customer_id']) ? (int)$criteria['customer_id'] : null;
        $agentId = !empty($criteria['agent_id']) ? (int)$criteria['agent_id'] : null;
        $priority = $criteria['priority'] ?? null;
        $status = $criteria['status'] ?? null;
        $channel = $criteria['channel'] ?? null;
        
        $cacheKey = $this->generateCacheKey($criteria);
        if ($cached = $this->cache->get($cacheKey)) {
            $this->logger->debug('Ticket search cache hit', ['key' => $cacheKey]);
            return $cached;
        }
        
        $conditions = ['deleted_at IS NULL'];
        $params = [];
        
        $fulltextColumns = 'subject, description, internal_notes';
        $hasFulltext = $this->hasFulltextIndex('support_tickets', $fulltextColumns);
        
        if ($hasFulltext) {
            $conditions[] = 'MATCH(' . $fulltextColumns . ') AGAINST(:query IN BOOLEAN MODE)';
            $params[':query'] = $this->prepareFulltextQuery($query);
        } else {
            $conditions[] = '(subject LIKE :query_like OR description LIKE :query_like)';
            $params[':query_like'] = '%' . $this->db->quote($query) . '%';
        }
        
        if ($customerId !== null) {
            $conditions[] = 'customer_id = :customer_id';
            $params[':customer_id'] = $customerId;
        }
        
        if ($agentId !== null) {
            $conditions[] = 'agent_id = :agent_id';
            $params[':agent_id'] = $agentId;
        }
        
        if ($priority !== null) {
            $conditions[] = 'priority = :priority';
            $params[':priority'] = $priority;
        }
        
        if ($status !== null) {
            $conditions[] = 'status = :status';
            $params[':status'] = $status;
        }
        
        if ($channel !== null) {
            $conditions[] = 'channel = :channel';
            $params[':channel'] = $channel;
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        $selectClause = 'id, customer_id, agent_id, subject, priority, status, 
                         channel, created_at, updated_at';
        $orderByClause = 'relevance DESC, created_at DESC';
        
        if ($hasFulltext) {
            $selectClause = '*, MATCH(' . $fulltextColumns . ') AGAINST(:query IN NATURAL LANGUAGE MODE) as relevance';
            $params[':query'] = $query;
            $orderByClause = 'relevance DESC, created_at DESC';
        }
        
        $countSql = "SELECT COUNT(*) FROM support_tickets WHERE {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalCount = (int)$countStmt->fetchColumn();
        
        $offset = ($page - 1) * $perPage;
        $searchSql = "
            SELECT {$selectClause}
            FROM support_tickets
            WHERE {$whereClause}
            ORDER BY {$orderByClause}
            LIMIT :max_results OFFSET :offset
        ";
        
        $stmt = $this->db->prepare($searchSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':max_results', $maxResults, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $filteredResults = array_filter($results, function($item) use ($minScore, $hasFulltext) {
            if (!$hasFulltext) {
                return true;
            }
            return ($item['relevance'] ?? 0) >= $minScore;
        });
        
        $elapsed = microtime(true) - $startTime;
        $this->logger->info('Ticket search executed', [
            'query' => $query,
            'total' => $totalCount,
            'returned' => count($filteredResults),
            'min_score' => $minScore,
            'duration_ms' => round($elapsed * 1000, 2)
        ]);
        
        $response = [
            'success' => true,
            'data' => array_values($filteredResults),
            'meta' => [
                'query' => $query,
                'total_results' => $totalCount,
                'returned_results' => count($filteredResults),
                'page' => $page,
                'per_page' => $perPage,
                'min_score' => $minScore,
                'search_mode' => $hasFulltext ? 'fulltext' : 'like',
                'duration_ms' => round($elapsed * 1000, 2)
            ]
        ];
        
        $this->cache->set($cacheKey, $response, 180);
        
        return $response;
    }

    private function hasFulltextIndex(string $table, string $columns): bool
    {
        static $cache = [];
        $cacheKey = $table . ':' . $columns;
        
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        
        $sql = "SELECT COUNT(*) FROM information_schema.statistics 
                WHERE table_schema = DATABASE() 
                AND table_name = :table 
                AND index_name = 'idx_fulltext'";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':table', $table);
        $stmt->execute();
        
        $cache[$cacheKey] = (int)$stmt->fetchColumn() > 0;
        
        return $cache[$cacheKey];
    }

    private function prepareFulltextQuery(string $query): string
    {
        $words = preg_split('/\s+/', trim($query));
        $prepared = array_map(function($word) {
            return '+' . $word . '*';
        }, $words);
        
        return implode(' ', $prepared);
    }

    private function generateCacheKey(array $criteria): string
    {
        ksort($criteria);
        return 'ticket_search:' . md5(json_encode($criteria));
    }
}
