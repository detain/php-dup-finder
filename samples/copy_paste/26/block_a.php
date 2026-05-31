<?php

declare(strict_types=1);

namespace App\View\Components\DataTable;

use Illuminate\Support\Collection;

final class PaginationControlRenderer
{
    public const DEFAULT_PAGE_SIZE = 15;
    public const MAX_PAGE_SIZE = 100;
    public const MIN_PAGE_SIZE = 1;
    public const PAGE_WINDOW = 5;

    public function renderControls(int $currentPage, int $totalPages, int $pageSize, int $totalItems): array
    {
        return [
            'page_size' => $this->determinePageSize($pageSize),
            'current_page' => $this->normalizeCurrentPage($currentPage, $totalPages),
            'total_pages' => $totalPages,
            'total_items' => $totalItems,
            'has_next' => $currentPage < $totalPages,
            'has_previous' => $currentPage > 1,
            'is_first' => $currentPage === 1,
            'is_last' => $currentPage === $totalPages || $totalPages === 0,
            'pages_in_window' => $this->calculatePageWindow($currentPage, $totalPages),
            'navigation' => $this->buildNavigation($currentPage, $totalPages),
        ];
    }

    public function renderPageSizeOptions(): array
    {
        return [
            ['value' => 10, 'label' => '10 per page'],
            ['value' => 15, 'label' => '15 per page'],
            ['value' => 25, 'label' => '25 per page'],
            ['value' => 50, 'label' => '50 per page'],
            ['value' => 100, 'label' => '100 per page'],
        ];
    }

    public function renderPageNumbers(int $currentPage, int $totalPages): array
    {
        $window = $this->calculatePageWindow($currentPage, $totalPages);
        $pages = [];

        foreach ($window as $page) {
            $pages[] = [
                'number' => $page,
                'is_current' => $page === $currentPage,
                'is_ellipsis' => $page === 0,
            ];
        }

        return $pages;
    }

    public function renderNavigation(int $currentPage, int $totalPages): array
    {
        return [
            'first' => $this->buildFirstUrl($currentPage),
            'previous' => $this->buildPreviousUrl($currentPage),
            'next' => $this->buildNextUrl($currentPage, $totalPages),
            'last' => $this->buildLastUrl($currentPage, $totalPages),
        ];
    }

    public function renderSummary(int $currentPage, int $pageSize, int $totalItems): array
    {
        $from = min(($currentPage - 1) * $pageSize + 1, $totalItems);
        $to = min($currentPage * $pageSize, $totalItems);

        return [
            'showing_from' => $from,
            'showing_to' => $to,
            'total' => $totalItems,
            'current_range' => "Showing {$from} to {$to} of {$totalItems}",
        ];
    }

    public function renderJumpToPageControl(int $currentPage, int $totalPages): array
    {
        $pages = [];

        for ($i = 1; $i <= $totalPages; $i++) {
            $pages[] = [
                'value' => $i,
                'label' => "Page {$i}",
                'is_current' => $i === $currentPage,
            ];
        }

        return [
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'pages' => $pages,
        ];
    }

    private function determinePageSize(int $size): int
    {
        if ($size < self::MIN_PAGE_SIZE) {
            return self::DEFAULT_PAGE_SIZE;
        }

        if ($size > self::MAX_PAGE_SIZE) {
            return self::MAX_PAGE_SIZE;
        }

        return $size;
    }

    private function normalizeCurrentPage(int $page, int $totalPages): int
    {
        if ($page < 1) {
            return 1;
        }

        if ($page > $totalPages && $totalPages > 0) {
            return $totalPages;
        }

        return $page;
    }

    private function calculatePageWindow(int $currentPage, int $totalPages): array
    {
        if ($totalPages === 0) {
            return [];
        }

        $halfWindow = (int) floor(self::PAGE_WINDOW / 2);
        $start = max(1, $currentPage - $halfWindow);
        $end = min($totalPages, $currentPage + $halfWindow);

        if ($end - $start < self::PAGE_WINDOW - 1) {
            if ($start === 1) {
                $end = min($totalPages, $start + self::PAGE_WINDOW - 1);
            } else {
                $start = max(1, $end - self::PAGE_WINDOW + 1);
            }
        }

        $pages = [];

        if ($start > 1) {
            $pages[] = 1;

            if ($start > 2) {
                $pages[] = 0;
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $pages[] = 0;
            }

            $pages[] = $totalPages;
        }

        return $pages;
    }

    private function buildNavigation(int $currentPage, int $totalPages): array
    {
        return [
            'first_page_url' => $currentPage > 1 ? $this->buildUrl(1) : null,
            'previous_page_url' => $currentPage > 1 ? $this->buildUrl($currentPage - 1) : null,
            'next_page_url' => $currentPage < $totalPages ? $this->buildUrl($currentPage + 1) : null,
            'last_page_url' => $currentPage < $totalPages ? $this->buildUrl($totalPages) : null,
        ];
    }

    private function buildFirstUrl(int $currentPage): ?string
    {
        return $currentPage > 1 ? $this->buildUrl(1) : null;
    }

    private function buildPreviousUrl(int $currentPage): ?string
    {
        return $currentPage > 1 ? $this->buildUrl($currentPage - 1) : null;
    }

    private function buildNextUrl(int $currentPage, int $totalPages): ?string
    {
        return $currentPage < $totalPages ? $this->buildUrl($currentPage + 1) : null;
    }

    private function buildLastUrl(int $currentPage, int $totalPages): ?string
    {
        return $currentPage < $totalPages ? $this->buildUrl($totalPages) : null;
    }

    private function buildUrl(int $page): string
    {
        return '?page=' . $page;
    }
}
