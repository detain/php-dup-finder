<?php
declare(strict_types=1);

namespace ContentRepo\Repository;

use Psr\Log\LoggerInterface;

final class ArticleRepository
{
    public function __construct(
        private readonly \PDO $connection,
        private readonly LoggerInterface $logger,
    ) {}

    public function findPaginated(PaginationParams $params, ArticleFilter $filter): PaginatedResult
    {
        $this->logger->debug('Finding paginated articles', [
            'page' => $params->getPage(),
            'per_page' => $params->getPerPage(),
        ]);

        $whereClause = $this->buildWhereClause($filter);
        $bindings = $this->buildBindings($filter);

        $countSql = "SELECT COUNT(*) FROM articles {$whereClause}";
        $stmt = $this->connection->prepare($countSql);
        $stmt->execute($bindings);
        $totalCount = (int)$stmt->fetchColumn();

        $limit = $params->getPerPage();
        $offset = $params->getOffset();
        $sql = "SELECT * FROM articles {$whereClause} ORDER BY {$params->getSortColumn()} {$params->getSortDirection()} LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->logger->debug('Articles retrieved', [
            'total_count' => $totalCount,
            'items_count' => count($items),
        ]);

        return new PaginatedResult(
            items: $items,
            totalCount: $totalCount,
            page: $params->getPage(),
            perPage: $params->getPerPage(),
            hasNextPage: ($params->getPage() * $params->getPerPage()) < $totalCount,
            hasPreviousPage: $params->getPage() > 1,
        );
    }

    private function buildWhereClause(ArticleFilter $filter): string
    {
        $conditions = [];

        if ($filter->getStatus() !== null) {
            $conditions[] = 'status = :status';
        }

        if ($filter->getCategoryId() !== null) {
            $conditions[] = 'category_id = :category_id';
        }

        if ($filter->getAuthorId() !== null) {
            $conditions[] = 'author_id = :author_id';
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

        if ($filter->getFeatured() !== null) {
            $conditions[] = 'is_featured = :featured';
        }

        if (empty($conditions)) {
            return '';
        }

        return 'WHERE ' . implode(' AND ', $conditions);
    }

    private function buildBindings(ArticleFilter $filter): array
    {
        $bindings = [];

        if ($filter->getStatus() !== null) {
            $bindings['status'] = $filter->getStatus();
        }

        if ($filter->getCategoryId() !== null) {
            $bindings['category_id'] = $filter->getCategoryId();
        }

        if ($filter->getAuthorId() !== null) {
            $bindings['author_id'] = $filter->getAuthorId();
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

        if ($filter->getFeatured() !== null) {
            $bindings['featured'] = $filter->getFeatured() ? 1 : 0;
        }

        return $bindings;
    }
}
