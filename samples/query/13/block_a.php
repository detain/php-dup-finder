<?php
declare(strict_types=1);

namespace App\Catalog\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use App\Cache\CacheManager;
use PDO;
use RuntimeException;

final class ProductSearchRepository
{
    private PDO $db;
    private LoggerInterface $logger;
    private CacheManager $cache;

    private const DEFAULT_MIN_SCORE = 0.5;
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
        
        $categoryId = !empty($criteria['category_id']) ? (int)$criteria['category_id'] : null;
        $brandId = !empty($criteria['brand_id']) ? (int)$criteria['brand_id'] : null;
        $priceMin = !empty($criteria['price_min']) ? (float)$criteria['price_min'] : null;
        $priceMax = !empty($criteria['price_max']) ? (float)$criteria['price_max'] : null;
        $inStock = isset($criteria['in_stock']) ? (bool)$criteria['in_stock'] : null;
        $status = $criteria['status'] ?? 'active';
        
        $cacheKey = $this->generateCacheKey($criteria);
        if ($cached = $this->cache->get($cacheKey)) {
            $this->logger->debug('Product search cache hit', ['key' => $cacheKey]);
            return $cached;
        }
        
        $conditions = ['deleted_at IS NULL', 'status = :status'];
        $params = [':status' => $status];

        // Relevance score calculation

        $fulltextColumns = 'name, description, sku, manufacturer';
        $hasFulltext = $this->hasFulltextIndex('products', $fulltextColumns);
        
        if ($hasFulltext) {
            $conditions[] = 'MATCH(' . $fulltextColumns . ') AGAINST(:query IN BOOLEAN MODE)';
            $params[':query'] = $this->prepareFulltextQuery($query);
        } else {
            $conditions[] = '(name LIKE :query_like OR sku LIKE :query_like OR description LIKE :query_like)';
            $params[':query_like'] = '%' . $this->db->quote($query) . '%';
        }
        
        if ($categoryId !== null) {
            $conditions[] = 'category_id = :category_id';
            $params[':category_id'] = $categoryId;
        }
        
        if ($brandId !== null) {
            $conditions[] = 'brand_id = :brand_id';
            $params[':brand_id'] = $brandId;
        }
        
        if ($priceMin !== null) {
            $conditions[] = 'price >= :price_min';
            $params[':price_min'] = $priceMin;
        }
        
        if ($priceMax !== null) {
            $conditions[] = 'price <= :price_max';
            $params[':price_max'] = $priceMax;
        }
        
        if ($inStock !== null) {
            $conditions[] = $inStock ? 'stock_quantity > 0' : 'stock_quantity = 0';
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        $selectClause = 'id, sku, name, category_id, brand_id, price, stock_quantity, 
                          visibility, status, created_at';
        $orderByClause = 'relevance DESC, created_at DESC';
        
        if ($hasFulltext) {
            $selectClause = '*, MATCH(' . $fulltextColumns . ') AGAINST(:query IN NATURAL LANGUAGE MODE) as relevance';
            $params[':query'] = $query;
            $orderByClause = 'relevance DESC, created_at DESC';
        }
        
        $countSql = "SELECT COUNT(*) FROM products WHERE {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalCount = (int)$countStmt->fetchColumn();
        
        $offset = ($page - 1) * $perPage;
        $searchSql = "
            SELECT {$selectClause}
            FROM products
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
        $this->logger->info('Product search executed', [
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
        
        $this->cache->set($cacheKey, $response, 300);
        
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
        return 'product_search:' . md5(json_encode($criteria));
    }
}
