<?php
declare(strict_types=1);

namespace App\Database\Search;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use App\Cache\CacheManager;
use PDO;

interface SearchableEntity
{
    public function getTableName(): string;
    public function getFulltextColumns(): string;
    public function getSelectColumns(): string;
    public function getDefaultSortColumn(): string;
    public function buildConditions(array $criteria, array &$params): array;
}

trait FulltextSearchBuilder
{
    private PDO $db;
    private LoggerInterface $logger;
    private CacheManager $cache;

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

    protected function prepareFulltextQuery(string $query): string
    {
        $words = preg_split('/\s+/', trim($query));
        $prepared = array_map(function($word) {
            return '+' . $word . '*';
        }, $words);
        
        return implode(' ', $prepared);
    }

    protected function buildFulltextCondition(string $columns, string $query, array &$params): string
    {
        $hasFulltext = $this->hasFulltextIndex(
            $this->getTableNameForEntity(),
            $columns
        );
        
        if ($hasFulltext) {
            $params[':query'] = $this->prepareFulltextQuery($query);
            return 'MATCH(' . $columns . ') AGAINST(:query IN BOOLEAN MODE)';
        }
        
        $params[':query_like'] = '%' . $this->db->quote($query) . '%';
        return '(' . $columns . ' LIKE :query_like)';
    }

    abstract protected function getTableNameForEntity(): string;
}

abstract class AbstractSearchRepository
{
    use FulltextSearchBuilder;

    protected PDO $db;
    protected LoggerInterface $logger;
    protected CacheManager $cache;

    protected const DEFAULT_MIN_SCORE = 0.5;
    protected const DEFAULT_MAX_RESULTS = 50;

    public function __construct(
        Connection $connection,
        LoggerInterface $logger,
        CacheManager $cache
    ) {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
        $this->cache = $cache;
    }

    abstract protected function getEntity(): SearchableEntity;

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
        
        $entity = $this->getEntity();
        $page = max(1, (int)($criteria['page'] ?? 1));
        $perPage = min(100, max(10, (int)($criteria['per_page'] ?? 25)));
        $minScore = (float)($criteria['min_score'] ?? self::DEFAULT_MIN_SCORE);
        $maxResults = min(500, max(10, (int)($criteria['max_results'] ?? self::DEFAULT_MAX_RESULTS)));
        
        $cacheKey = $this->generateCacheKey($criteria);
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }
        
        $params = [];
        $conditions = $entity->buildConditions($criteria, $params);
        
        $conditions[] = $this->buildFulltextCondition(
            $entity->getFulltextColumns(),
            $query,
            $params
        );
        
        $whereClause = implode(' AND ', $conditions);
        $hasFulltext = $this->hasFulltextIndex($entity->getTableName(), $entity->getFulltextColumns());
        
        $selectClause = $entity->getSelectColumns();
        if ($hasFulltext) {
            $params[':query'] = $query;
            $selectClause = '*, MATCH(' . $entity->getFulltextColumns() . ') AGAINST(:query IN NATURAL LANGUAGE MODE) as relevance';
        }
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM {$entity->getTableName()} WHERE {$whereClause}");
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalCount = (int)$countStmt->fetchColumn();
        
        $offset = ($page - 1) * $perPage;
        $searchSql = "
            SELECT {$selectClause}
            FROM {$entity->getTableName()}
            WHERE {$whereClause}
            ORDER BY relevance DESC, {$entity->getDefaultSortColumn()} DESC
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
            return !$hasFulltext || ($item['relevance'] ?? 0) >= $minScore;
        });
        
        $elapsed = microtime(true) - $startTime;
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
        
        $this->cache->set($cacheKey, $response, 300);
        
        return $response;
    }

    private function generateCacheKey(array $criteria): string
    {
        ksort($criteria);
        return md5(json_encode($criteria));
    }
}
