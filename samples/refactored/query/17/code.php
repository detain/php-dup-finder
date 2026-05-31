<?php
declare(strict_types=1);

namespace App\Analytics\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

abstract class AbstractAnalyticsRepository
{
    protected PDO $db;
    protected LoggerInterface $logger;

    abstract protected function getTable(): string;
    abstract protected function getSelectColumns(): string;
    abstract protected function getAllowedSorts(): array;
    abstract protected function getTimestampColumn(): string;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
    }

    protected function buildConditions(array $params): array
    {
        $conditions = ['1=1'];
        $bindings = [];
        
        $this->addUserConditions($params, $conditions, $bindings);
        $this->addSessionConditions($params, $conditions, $bindings);
        $this->addDeviceConditions($params, $conditions, $bindings);
        $this->addGeoConditions($params, $conditions, $bindings);
        $this->addTimestampConditions($params, $conditions, $bindings);
        $this->addSearchConditions($params, $conditions, $bindings);
        $this->addEntitySpecificConditions($params, $conditions, $bindings);
        
        return [$conditions, $bindings];
    }

    protected function addUserConditions(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['user_id'])) {
            $conditions[] = 'user_id = :user_id';
            $bindings[':user_id'] = (int)$params['user_id'];
        }
    }

    protected function addSessionConditions(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['session_id'])) {
            $conditions[] = 'session_id = :session_id';
            $bindings[':session_id'] = $params['session_id'];
        }
    }

    protected function addDeviceConditions(array $params, array &$conditions, array &$bindings): void
    {
        $deviceFields = ['device_type', 'browser', 'operating_system'];
        foreach ($deviceFields as $field) {
            if (!empty($params[$field])) {
                $conditions[] = "{$field} = :{$field}";
                $bindings[":{$field}"] = $params[$field];
            }
        }
    }

    protected function addGeoConditions(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['country_code'])) {
            $conditions[] = 'country_code = :country_code';
            $bindings[':country_code'] = $params['country_code'];
        }
    }

    protected function addTimestampConditions(array $params, array &$conditions, array &$bindings): void
    {
        $timestampCol = $this->getTimestampColumn();
        $timePrefix = str_replace('_at', '', $timestampCol);
        
        if (!empty($params["{$timePrefix}_after"])) {
            $conditions[] = "{$timestampCol} >= :{$timePrefix}_after";
            $bindings[":{$timePrefix}_after"] = $params["{$timePrefix}_after"];
        }
        if (!empty($params["{$timePrefix}_before"])) {
            $conditions[] = "{$timestampCol} <= :{$timePrefix}_before";
            $bindings[":{$timePrefix}_before"] = $params["{$timePrefix}_before"];
        }
    }

    protected function addSearchConditions(array $params, array &$conditions, array &$bindings): void
    {
        if (!empty($params['search'])) {
            $conditions[] = '(session_id LIKE :search OR page_url LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
    }

    protected function addEntitySpecificConditions(array $params, array &$conditions, array &$bindings): void
    {
    }

    public function findPaginated(array $params = []): array
    {
        $startTime = microtime(true);
        
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(10, (int)($params['per_page'] ?? 25)));
        $sortBy = $params['sort_by'] ?? $this->getAllowedSorts()[0];
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        if (!in_array($sortBy, $this->getAllowedSorts(), true)) {
            $sortBy = $this->getAllowedSorts()[0];
        }
        
        [$conditions, $bindings] = $this->buildConditions($params);
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->getTable()} WHERE {$whereClause}");
        $this->bindValues($countStmt, $bindings);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT {$this->getSelectColumns()}
            FROM {$this->getTable()}
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
        
        $this->logger->info('Analytics pagination query executed', [
            'table' => $this->getTable(),
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
