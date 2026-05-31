<?php

declare(strict_types=1);

namespace App\Repositories\Search;

use Illuminate\Database\Eloquent\Builder;

final class ResultPaginationHelper
{
    private const ITEMS_PER_PAGE = 15;
    private const MAX_ITEMS = 100;
    private const MIN_ITEMS = 1;
    private const FIRST_PAGE = 1;

    public function applyPagination(Builder $query, int $page, int $perPage): array
    {
        $page = $this->fixPageValue($page);
        $perPage = $this->fixPerPageValue($perPage);
        $total = $query->count();
        $totalPages = $this->determinePageCount($total, $perPage);

        $offsetValue = $this->calculateOffsetValue($page, $perPage);
        $results = $query->skip($offsetValue)->take($perPage)->get();

        return $this->constructPaginatedResult(
            $results,
            $total,
            $totalPages,
            $page,
            $perPage
        );
    }

    public function applyPaginationWithCursor(Builder $query, int $page, int $perPage, ?string $cursor): array
    {
        $page = $this->fixPageValue($page);
        $perPage = $this->fixPerPageValue($perPage);

        if ($cursor !== null) {
            $query->where('id', '>', $cursor);
        }

        return $this->applyPagination($query, $page, $perPage);
    }

    public function applyPaginationWithSummary(Builder $query, int $page, int $perPage): array
    {
        $result = $this->applyPagination($query, $page, $perPage);

        $result['summary'] = $this->createPaginationSummary(
            $result['current_page'],
            $result['total_pages'],
            $result['total_items'],
            $result['page_size']
        );

        return $result;
    }

    public function applyPaginationWithRange(Builder $query, int $page, int $perPage, int $minPerPage, int $maxPerPage): array
    {
        $page = $this->fixPageValue($page);
        $perPage = $this->fixPerPageValue($perPage);

        if ($perPage < $minPerPage) {
            $perPage = $minPerPage;
        }

        if ($perPage > $maxPerPage) {
            $perPage = $maxPerPage;
        }

        return $this->applyPagination($query, $page, $perPage);
    }

    public function applyOffsetPagination(Builder $query, int $offset, int $limit): array
    {
        $limit = $this->fixPerPageValue($limit);
        $total = $query->count();
        $page = $this->computePageFromOffset($offset, $limit);
        $totalPages = $this->determinePageCount($total, $limit);

        $results = $query->skip($offset)->take($limit)->get();

        return $this->constructPaginatedResult(
            $results,
            $total,
            $totalPages,
            $page,
            $limit
        );
    }

    private function fixPageValue(int $page): int
    {
        return $page < 1 ? self::FIRST_PAGE : $page;
    }

    private function fixPerPageValue(int $perPage): int
    {
        if ($perPage < self::MIN_ITEMS) {
            return self::ITEMS_PER_PAGE;
        }

        if ($perPage > self::MAX_ITEMS) {
            return self::MAX_ITEMS;
        }

        return $perPage;
    }

    private function determinePageCount(int $totalItems, int $perPage): int
    {
        if ($totalItems === 0) {
            return 0;
        }

        return (int) ceil($totalItems / $perPage);
    }

    private function calculateOffsetValue(int $page, int $perPage): int
    {
        return ($page - 1) * $perPage;
    }

    private function computePageFromOffset(int $offset, int $limit): int
    {
        return (int) floor($offset / $limit) + 1;
    }

    private function constructPaginatedResult(
        $items,
        int $totalItems,
        int $totalPages,
        int $currentPage,
        int $perPage
    ): array {
        return [
            'data' => $items,
            'meta' => [
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'current_page' => $currentPage,
                'per_page' => $perPage,
                'has_more' => $currentPage < $totalPages,
                'has_less' => $currentPage > 1,
            ],
        ];
    }

    private function createPaginationSummary(int $page, int $totalPages, int $totalItems, int $perPage): array
    {
        $from = ($page - 1) * $perPage + 1;
        $to = min($page * $perPage, $totalItems);

        return [
            'from' => $totalItems > 0 ? $from : null,
            'to' => $totalItems > 0 ? $to : null,
            'total' => $totalItems,
            'showing' => $totalItems > 0 ? "{$from}-{$to} of {$totalItems}" : 'No results',
        ];
    }
}
