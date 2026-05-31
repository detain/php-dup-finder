<?php
declare(strict_types=1);

namespace ContentRepo\Repository;

use Psr\Log\LoggerInterface;

final class PageRepository
{
    public function __construct(
        private readonly \PDO $connection,
        private readonly LoggerInterface $logger,
    ) {}

    public function findPaginated(PaginationParams $params, PageFilter $filter): PaginatedResult
    {
        $this->logger->debug('Finding paginated pages', [
            'page' => $params->getPage(),
            'per_page' => $params->getPerPage(),
        ]);

        $whereClause = $this->buildWhereClause($filter);
        $bindings = $this->buildBindings($filter);

        $countSql = "SELECT COUNT(*) FROM pages {$whereClause}";
        $stmt = $this->connection->prepare($countSql);
        $stmt->execute($bindings);
        $totalCount = (int)$stmt->fetchColumn();

        $limit = $params->getPerPage();
        $offset = $params->getOffset();
        $sql = "SELECT * FROM pages {$whereClause} ORDER BY {$params->getSortColumn()} {$params->getSortDirection()} LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->logger->debug('Pages retrieved', [
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

    private function buildWhereClause(PageFilter $filter): string
    {
        $conditions = [];

        if ($filter->getStatus() !== null) {
            $conditions[] = 'status = :status';
        }

        if ($filter->getTemplateId() !== null) {
            $conditions[] = 'template_id = :template_id';
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

        if ($filter->getPublished() !== null) {
            $conditions[] = 'is_published = :published';
        }

        if (empty($conditions)) {
            return '';
        }

        return 'WHERE ' . implode(' AND ', $conditions);
    }

    private function buildBindings(PageFilter $filter): array
    {
        $bindings = [];

        if ($filter->getStatus() !== null) {
            $bindings['status'] = $filter->getStatus();
        }

        if ($filter->getTemplateId() !== null) {
            $bindings['template_id'] = $filter->getTemplateId();
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

        if ($filter->getPublished() !== null) {
            $bindings['published'] = $filter->getPublished() ? 1 : 0;
        }

        return $bindings;
    }
}
