<?php

declare(strict_types=1);

namespace App\DataTables\Common;

use Illuminate\Support\Collection;

final class PaginationProcessor
{
    private const DEFAULT_PAGE_SIZE = 15;
    private const MAX_PAGE_SIZE = 100;
    private const MIN_PAGE_SIZE = 1;
    private const DEFAULT_PAGE = 1;

    public function paginate(array $items, int $page, int $pageSize): array
    {
        $page = $this->normalizePageNumber($page);
        $pageSize = $this->normalizePageSize($pageSize);
        $totalItems = count($items);
        $totalPages = $this->calculateTotalPages($totalItems, $pageSize);

        $offset = $this->computeOffset($page, $pageSize);
        $paginatedItems = $this->sliceItems($items, $offset, $pageSize);

        return $this->buildPaginationResult(
            $paginatedItems,
            $totalItems,
            $totalPages,
            $page,
            $pageSize
        );
    }

    public function paginateCollection(Collection $collection, int $page, int $pageSize): array
    {
        $page = $this->normalizePageNumber($page);
        $pageSize = $this->normalizePageSize($pageSize);
        $totalItems = $collection->count();
        $totalPages = $this->calculateTotalPages($totalItems, $pageSize);

        $offset = $this->computeOffset($page, $pageSize);
        $paginatedItems = $collection->slice($offset, $pageSize)->values();

        return $this->buildPaginationResult(
            $paginatedItems->all(),
            $totalItems,
            $totalPages,
            $page,
            $pageSize
        );
    }

    public function paginateWithNavigation(array $items, int $currentPage, int $pageSize): array
    {
        $result = $this->paginate($items, $currentPage, $pageSize);

        $result['navigation'] = $this->generateNavigation(
            $currentPage,
            $result['total_pages'],
            $result['total_items']
        );

        return $result;
    }

    public function paginateWithBoundsChecking(array $items, int $page, int $pageSize, int $maxItems): array
    {
        $page = $this->normalizePageNumber($page);
        $pageSize = $this->normalizePageSize($pageSize);

        if ($pageSize > $maxItems) {
            $pageSize = $maxItems;
        }

        return $this->paginate($items, $page, $pageSize);
    }

    public function paginateWithWindow(array $items, int $page, int $pageSize, int $windowSize = 5): array
    {
        $result = $this->paginate($items, $page, $pageSize);

        $result['window'] = $this->generatePageWindow(
            $page,
            $result['total_pages'],
            $windowSize
        );

        return $result;
    }

    public function paginateWithSkip(array $items, int $skip, int $take): array
    {
        $totalItems = count($items);
        $page = (int) ceil(($skip + 1) / $take);
        $pageSize = $take;

        $result = $this->paginate($items, $page, $pageSize);
        $result['skip'] = $skip;

        return $result;
    }

    private function normalizePageNumber(int $page): int
    {
        if ($page < 1) {
            return self::DEFAULT_PAGE;
        }

        return $page;
    }

    private function normalizePageSize(int $size): int
    {
        if ($size < self::MIN_PAGE_SIZE) {
            return self::DEFAULT_PAGE_SIZE;
        }

        if ($size > self::MAX_PAGE_SIZE) {
            return self::MAX_PAGE_SIZE;
        }

        return $size;
    }

    private function calculateTotalPages(int $totalItems, int $pageSize): int
    {
        if ($totalItems === 0) {
            return 0;
        }

        return (int) ceil($totalItems / $pageSize);
    }

    private function computeOffset(int $page, int $pageSize): int
    {
        return ($page - 1) * $pageSize;
    }

    private function sliceItems(array $items, int $offset, int $pageSize): array
    {
        return array_slice($items, $offset, $pageSize);
    }

    private function buildPaginationResult(
        array $items,
        int $totalItems,
        int $totalPages,
        int $currentPage,
        int $pageSize
    ): array {
        return [
            'items' => $items,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'current_page' => $currentPage,
            'page_size' => $pageSize,
            'has_next_page' => $currentPage < $totalPages,
            'has_previous_page' => $currentPage > 1,
            'is_first_page' => $currentPage === 1,
            'is_last_page' => $currentPage === $totalPages || $totalPages === 0,
        ];
    }

    private function generateNavigation(int $currentPage, int $totalPages, int $totalItems): array
    {
        return [
            'first' => 1,
            'last' => $totalPages,
            'previous' => $currentPage > 1 ? $currentPage - 1 : null,
            'next' => $currentPage < $totalPages ? $currentPage + 1 : null,
            'total' => $totalItems,
        ];
    }

    private function generatePageWindow(int $currentPage, int $totalPages, int $windowSize): array
    {
        $halfWindow = (int) floor($windowSize / 2);
        $start = max(1, $currentPage - $halfWindow);
        $end = min($totalPages, $currentPage + $halfWindow);

        if ($end - $start < $windowSize - 1) {
            if ($start === 1) {
                $end = min($totalPages, $start + $windowSize - 1);
            } else {
                $start = max(1, $end - $windowSize + 1);
            }
        }

        $pages = [];
        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }

        return $pages;
    }
}
