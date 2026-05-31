<?php
declare(strict_types=1);

namespace ContentRepo\Shared;

interface PaginatedRepositoryInterface
{
    public function findPaginated(PaginationParams $params, mixed $filter): PaginatedResult;
}

interface FilterInterface
{
    public function getStatus(): ?string;
    public function getSearchQuery(): ?string;
    public function getCreatedAfter(): ?\DateTimeImmutable;
    public function getCreatedBefore(): ?\DateTimeImmutable;
}

abstract class BasePaginatedRepository implements PaginatedRepositoryInterface
{
    protected \PDO $connection;
    protected LoggerInterface $logger;
    protected string $tableName;

    public function findPaginated(PaginationParams $params, mixed $filter): PaginatedResult
    {
        $this->logger->debug("Finding paginated {$this->tableName}", [
            'page' => $params->getPage(),
            'per_page' => $params->getPerPage(),
        ]);

        $whereClause = $this->buildWhereClause($filter);
        $bindings = $this->buildBindings($filter);

        $totalCount = $this->getTotalCount($whereClause, $bindings);

        $items = $this->getItems($params, $whereClause, $bindings);

        return new PaginatedResult(
            items: $items,
            totalCount: $totalCount,
            page: $params->getPage(),
            perPage: $params->getPerPage(),
            hasNextPage: ($params->getPage() * $params->getPerPage()) < $totalCount,
            hasPreviousPage: $params->getPage() > 1,
        );
    }

    protected function getTotalCount(string $whereClause, array $bindings): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->tableName} {$whereClause}";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($bindings);
        return (int)$stmt->fetchColumn();
    }

    protected function getItems(PaginationParams $params, string $whereClause, array $bindings): array
    {
        $limit = $params->getPerPage();
        $offset = $params->getOffset();
        $sql = "SELECT * FROM {$this->tableName} {$whereClause} ORDER BY {$params->getSortColumn()} {$params->getSortDirection()} LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    protected function buildWhereClauseFromFilter(FilterInterface $filter, array $fieldMappings): string
    {
        $conditions = [];

        foreach ($fieldMappings as $filterMethod => $dbColumn) {
            $value = $filter->$filterMethod();
            if ($value !== null) {
                if (is_string($value) && str_contains($dbColumn, 'LIKE')) {
                    $conditions[] = str_replace('?', $dbColumn, ':search');
                } else {
                    $conditions[] = "{$dbColumn} = :{$filterMethod}";
                }
            }
        }

        if ($filter->getSearchQuery() !== null && $filter->getSearchQuery() !== '') {
            $conditions[] = '(title LIKE :search OR content LIKE :search)';
        }

        if ($filter->getCreatedAfter() !== null) {
            $conditions[] = 'created_at >= :created_after';
        }

        if ($filter->getCreatedBefore() !== null) {
            $conditions[] = 'created_at <= :created_before';
        }

        return empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
    }

    protected function buildBindingsFromFilter(FilterInterface $filter, array $fieldMappings): array
    {
        $bindings = [];

        foreach ($fieldMappings as $filterMethod => $dbColumn) {
            $value = $filter->$filterMethod();
            if ($value !== null) {
                $bindings[$filterMethod] = $value;
            }
        }

        if ($filter->getSearchQuery() !== null && $filter->getSearchQuery() !== '') {
            $bindings['search'] = '%' . $filter->getSearchQuery() . '%';
        }

        if ($filter->getCreatedAfter() !== null) {
            $bindings['created_after'] = $filter->getCreatedAfter()->format('Y-m-d H:i:s');
        }

        if ($filter->getCreatedBefore() !== null) {
            $bindings['created_before'] = $filter->getCreatedBefore()->format('Y-m-d H:i:s');
        }

        return $bindings;
    }
}

final class ArticleRepository extends BasePaginatedRepository
{
    protected string $tableName = 'articles';

    public function findPaginated(PaginationParams $params, ArticleFilter $filter): PaginatedResult
    {
        $fieldMappings = [
            'getStatus' => 'status',
            'getCategoryId' => 'category_id',
            'getAuthorId' => 'author_id',
            'getFeatured' => 'is_featured',
        ];

        $whereClause = $this->buildWhereClauseFromFilter($filter, $fieldMappings);
        $bindings = $this->buildBindingsFromFilter($filter, $fieldMappings);

        $totalCount = $this->getTotalCount($whereClause, $bindings);
        $items = $this->getItems($params, $whereClause, $bindings);

        return new PaginatedResult(
            items: $items,
            totalCount: $totalCount,
            page: $params->getPage(),
            perPage: $params->getPerPage(),
            hasNextPage: ($params->getPage() * $params->getPerPage()) < $totalCount,
            hasPreviousPage: $params->getPage() > 1,
        );
    }
}
