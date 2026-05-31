<?php
declare(strict_types=1);

namespace App\Analytics\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class ConversionRepository
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
        $sortBy = $params['sort_by'] ?? 'converted_at';
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        $allowedSorts = ['converted_at', 'conversion_type', 'user_id', 'revenue'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'converted_at';
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
        
        if (!empty($params['conversion_type'])) {
            $conditions[] = 'conversion_type = :conversion_type';
            $bindings[':conversion_type'] = $params['conversion_type'];
        }
        
        if (!empty($params['campaign_id'])) {
            $conditions[] = 'campaign_id = :campaign_id';
            $bindings[':campaign_id'] = (int)$params['campaign_id'];
        }
        
        if (!empty($params['source'])) {
            $conditions[] = 'source = :source';
            $bindings[':source'] = $params['source'];
        }
        
        if (!empty($params['medium'])) {
            $conditions[] = 'medium = :medium';
            $bindings[':medium'] = $params['medium'];
        }
        
        if (!empty($params['keyword'])) {
            $conditions[] = 'keyword LIKE :keyword';
            $bindings[':keyword'] = '%' . $params['keyword'] . '%';
        }
        
        if (!empty($params['country_code'])) {
            $conditions[] = 'country_code = :country_code';
            $bindings[':country_code'] = $params['country_code'];
        }
        
        if (!empty($params['converted_after'])) {
            $conditions[] = 'converted_at >= :converted_after';
            $bindings[':converted_after'] = $params['converted_after'];
        }
        
        if (!empty($params['converted_before'])) {
            $conditions[] = 'converted_at <= :converted_before';
            $bindings[':converted_before'] = $params['converted_before'];
        }
        
        if (!empty($params['min_revenue'])) {
            $conditions[] = 'revenue >= :min_revenue';
            $bindings[':min_revenue'] = (float)$params['min_revenue'];
        }
        
        if (!empty($params['max_revenue'])) {
            $conditions[] = 'revenue <= :max_revenue';
            $bindings[':max_revenue'] = (float)$params['max_revenue'];
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(session_id LIKE :search OR transaction_id LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM conversions WHERE {$whereClause}");
        $this->bindValues($countStmt, $bindings);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT id, user_id, session_id, conversion_type, campaign_id,
                   source, medium, keyword, country_code, revenue,
                   currency, transaction_id, converted_at, created_at
            FROM conversions
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
        
        $this->logger->info('Conversions pagination query executed', [
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

    public function recordConversion(array $conversionData): int
    {
        $sql = "INSERT INTO conversions (user_id, session_id, conversion_type, campaign_id,
                                         source, medium, keyword, country_code, revenue,
                                         currency, transaction_id, converted_at, created_at)
                VALUES (:user_id, :session_id, :conversion_type, :campaign_id,
                        :source, :medium, :keyword, :country_code, :revenue,
                        :currency, :transaction_id, :converted_at, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $conversionData['user_id'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':session_id', $conversionData['session_id']);
        $stmt->bindValue(':conversion_type', $conversionData['conversion_type']);
        $stmt->bindValue(':campaign_id', $conversionData['campaign_id'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':source', $conversionData['source'] ?? null);
        $stmt->bindValue(':medium', $conversionData['medium'] ?? null);
        $stmt->bindValue(':keyword', $conversionData['keyword'] ?? null);
        $stmt->bindValue(':country_code', $conversionData['country_code'] ?? null);
        $stmt->bindValue(':revenue', $conversionData['revenue'] ?? 0.0);
        $stmt->bindValue(':currency', $conversionData['currency'] ?? 'USD');
        $stmt->bindValue(':transaction_id', $conversionData['transaction_id'] ?? null);
        $stmt->bindValue(':converted_at', $conversionData['converted_at'] ?? date('Y-m-d H:i:s'));
        
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
