<?php

declare(strict_types=1);

namespace App\Api\V1\Responses;

final class PagedResponseBuilder
{
    private const DEFAULT_LIMIT = 15;
    private const MAX_LIMIT = 100;
    private const MIN_LIMIT = 1;
    private const DEFAULT_OFFSET = 0;

    public function buildPaginatedResponse(array $data, int $offset, int $limit): array
    {
        $limit = $this->sanitizeLimit($limit);
        $offset = $this->sanitizeOffset($offset);
        $totalCount = count($data);
        $totalPages = $this->computeTotalPages($totalCount, $limit);
        $currentPage = $this->deriveCurrentPage($offset, $limit);

        $slicedData = array_slice($data, $offset, $limit);

        return $this->formPaginatedResponse(
            $slicedData,
            $totalCount,
            $totalPages,
            $currentPage,
            $limit,
            $offset
        );
    }

    public function buildCursorPaginatedResponse(array $data, int $offset, int $limit, ?string $nextCursor): array
    {
        $result = $this->buildPaginatedResponse($data, $offset, $limit);
        $result['meta']['next_cursor'] = $nextCursor;

        return $result;
    }

    public function buildNumberedPageResponse(array $data, int $pageNumber, int $itemsPerPage): array
    {
        $pageNumber = $this->ensureValidPage($pageNumber);
        $itemsPerPage = $this->sanitizeLimit($itemsPerPage);
        $offset = $this->computeOffset($pageNumber, $itemsPerPage);

        return $this->buildPaginatedResponse($data, $offset, $itemsPerPage);
    }

    public function buildInfiniteScrollResponse(array $data, int $offset, int $limit, bool $hasMore): array
    {
        $result = $this->buildPaginatedResponse($data, $offset, $limit);
        $result['meta']['has_more'] = $hasMore;

        return $result;
    }

    public function buildPageResponseWithMetadata(array $data, int $page, int $pageSize, array $extraMetadata): array
    {
        $result = $this->buildPaginatedResponse($data, ($page - 1) * $pageSize, $pageSize);
        $result['meta'] = array_merge($result['meta'], $extraMetadata);

        return $result;
    }

    private function sanitizeLimit(int $limit): int
    {
        if ($limit < self::MIN_LIMIT) {
            return self::DEFAULT_LIMIT;
        }

        if ($limit > self::MAX_LIMIT) {
            return self::MAX_LIMIT;
        }

        return $limit;
    }

    private function sanitizeOffset(int $offset): int
    {
        return $offset < 0 ? self::DEFAULT_OFFSET : $offset;
    }

    private function computeTotalPages(int $totalItems, int $limit): int
    {
        if ($totalItems === 0) {
            return 0;
        }

        return (int) ceil($totalItems / $limit);
    }

    private function deriveCurrentPage(int $offset, int $limit): int
    {
        if ($limit === 0) {
            return 1;
        }

        return (int) floor($offset / $limit) + 1;
    }

    private function computeOffset(int $pageNumber, int $itemsPerPage): int
    {
        return ($pageNumber - 1) * $itemsPerPage;
    }

    private function ensureValidPage(int $pageNumber): int
    {
        return $pageNumber < 1 ? 1 : $pageNumber;
    }

    private function formPaginatedResponse(
        array $items,
        int $totalItems,
        int $totalPages,
        int $currentPage,
        int $limit,
        int $offset
    ): array {
        return [
            'data' => $items,
            'meta' => [
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'current_page' => $currentPage,
                'per_page' => $limit,
                'offset' => $offset,
                'has_next' => $currentPage < $totalPages,
                'has_previous' => $currentPage > 1,
                'is_first' => $currentPage === 1,
                'is_last' => $currentPage >= $totalPages,
            ],
        ];
    }
}
