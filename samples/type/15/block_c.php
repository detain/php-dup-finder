<?php
declare(strict_types=1);

namespace ContentRepo\Repository;

use Psr\Log\LoggerInterface;

final class MediaRepository
{
    public function __construct(
        private readonly \PDO $connection,
        private readonly LoggerInterface $logger,
    ) {}

    public function findPaginated(PaginationParams $params, MediaFilter $filter): PaginatedResult
    {
        $this->logger->debug('Finding paginated media', [
            'page' => $params->getPage(),
            'per_page' => $params->getPerPage(),
        ]);

        $whereClause = $this->buildWhereClause($filter);
        $bindings = $this->buildBindings($filter);

        $countSql = "SELECT COUNT(*) FROM media {$whereClause}";
        $stmt = $this->connection->prepare($countSql);
        $stmt->execute($bindings);
        $totalCount = (int)$stmt->fetchColumn();

        $limit = $params->getPerPage();
        $offset = $params->getOffset();
        $sql = "SELECT * FROM media {$whereClause} ORDER BY {$params->getSortColumn()} {$params->getSortDirection()} LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->logger->debug('Media retrieved', [
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

    private function buildWhereClause(MediaFilter $filter): string
    {
        $conditions = [];

        if ($filter->getMimeType() !== null) {
            $conditions[] = 'mime_type = :mime_type';
        }

        if ($filter->getFolderId() !== null) {
            $conditions[] = 'folder_id = :folder_id';
        }

        if ($filter->getUploadedBy() !== null) {
            $conditions[] = 'uploaded_by = :uploaded_by';
        }

        if ($filter->getSearchQuery() !== null && $filter->getSearchQuery() !== '') {
            $conditions[] = '(filename LIKE :search OR alt_text LIKE :search)';
        }

        if ($filter->getUploadedAfter() !== null) {
            $conditions[] = 'uploaded_at >= :uploaded_after';
        }

        if ($filter->getUploadedBefore() !== null) {
            $conditions[] = 'uploaded_at <= :uploaded_before';
        }

        if ($filter->getFileType() !== null) {
            $conditions[] = 'file_type = :file_type';
        }

        if (empty($conditions)) {
            return '';
        }

        return 'WHERE ' . implode(' AND ', $conditions);
    }

    private function buildBindings(MediaFilter $filter): array
    {
        $bindings = [];

        if ($filter->getMimeType() !== null) {
            $bindings['mime_type'] = $filter->getMimeType();
        }

        if ($filter->getFolderId() !== null) {
            $bindings['folder_id'] = $filter->getFolderId();
        }

        if ($filter->getUploadedBy() !== null) {
            $bindings['uploaded_by'] = $filter->getUploadedBy();
        }

        if ($filter->getSearchQuery() !== null && $filter->getSearchQuery() !== '') {
            $bindings['search'] = '%' . $filter->getSearchQuery() . '%';
        }

        if ($filter->getUploadedAfter() !== null) {
            $bindings['uploaded_after'] = $filter->getUploadedAfter()->format('Y-m-d H:i:s');
        }

        if ($filter->getUploadedBefore() !== null) {
            $bindings['uploaded_before'] = $filter->getUploadedBefore()->format('Y-m-d H:i:s');
        }

        if ($filter->getFileType() !== null) {
            $bindings['file_type'] = $filter->getFileType();
        }

        return $bindings;
    }
}
