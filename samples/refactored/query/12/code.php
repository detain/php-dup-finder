<?php
declare(strict_types=1);

namespace App\Database\Pagination;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use App\Security\CurrentUser;
use PDO;

interface PaginationParams
{
    public function getPage(): int;
    public function getPerPage(): int;
    public function getSortBy(): string;
    public function getSortDir(): string;
    public function getAllowedSortColumns(): array;
    public function getConditions(): array;
    public function getBindings(): array;
    public function getSearchField(): ?string;
}

abstract class AbstractPaginatedRepository
{
    protected PDO $db;
    protected LoggerInterface $logger;
    protected CurrentUser $currentUser;

    public function __construct(
        Connection $connection,
        LoggerInterface $logger,
        CurrentUser $currentUser
    ) {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
        $this->currentUser = $currentUser;
    }

    abstract protected function getTableName(): string;
    abstract protected function getSelectColumns(): string;
    abstract protected function createParams(array $rawParams): PaginationParams;

    public function fetchPaginated(array $params = []): array
    {
        $startTime = microtime(true);
        $paginationParams = $this->createParams($params);
        
        $page = $paginationParams->getPage();
        $perPage = $paginationParams->getPerPage();
        $sortBy = $paginationParams->getSortBy();
        $sortDir = $paginationParams->getSortDir();
        
        $conditions = $paginationParams->getConditions();
        $bindings = $paginationParams->getBindings();
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM {$this->getTableName()} WHERE {$whereClause}"
        );
        foreach ($bindings as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT {$this->getSelectColumns()}
            FROM {$this->getTableName()}
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
        
        $this->logger->info('Paginated query executed', [
            'table' => $this->getTableName(),
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
}

final class AuditLogParams implements PaginationParams
{
    private array $params;
    
    public function __construct(array $params)
    {
        $this->params = $params;
    }
    
    public function getPage(): int
    {
        return max(1, (int)($this->params['page'] ?? 1));
    }
    
    public function getPerPage(): int
    {
        return min(100, max(10, (int)($this->params['per_page'] ?? 25)));
    }
    
    public function getSortBy(): string
    {
        return $this->params['sort_by'] ?? 'created_at';
    }
    
    public function getSortDir(): string
    {
        return strtoupper($this->params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    }
    
    public function getAllowedSortColumns(): array
    {
        return ['created_at', 'action', 'entity_type', 'user_id', 'ip_address'];
    }
    
    public function getConditions(): array
    {
        $conditions = ['1=1'];
        if (!empty($this->params['user_id'])) {
            $conditions[] = 'user_id = :user_id';
        }
        if (!empty($this->params['action'])) {
            $conditions[] = 'action = :action';
        }
        if (!empty($this->params['entity_type'])) {
            $conditions[] = 'entity_type = :entity_type';
        }
        if (!empty($this->params['date_from'])) {
            $conditions[] = 'created_at >= :date_from';
        }
        if (!empty($this->params['date_to'])) {
            $conditions[] = 'created_at <= :date_to';
        }
        return $conditions;
    }
    
    public function getBindings(): array
    {
        $bindings = [];
        if (!empty($this->params['user_id'])) {
            $bindings[':user_id'] = (int)$this->params['user_id'];
        }
        if (!empty($this->params['action'])) {
            $bindings[':action'] = $this->params['action'];
        }
        if (!empty($this->params['entity_type'])) {
            $bindings[':entity_type'] = $this->params['entity_type'];
        }
        if (!empty($this->params['date_from'])) {
            $bindings[':date_from'] = $this->params['date_from'];
        }
        if (!empty($this->params['date_to'])) {
            $bindings[':date_to'] = $this->params['date_to'];
        }
        return $bindings;
    }
    
    public function getSearchField(): string
    {
        return 'entity_id';
    }
}
