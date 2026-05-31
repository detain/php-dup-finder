<?php

declare(strict_types=1);

namespace App\DataGrid\Controls;

final class TablePaginationHelper
{
    private const ITEMS_PER_PAGE_DEFAULT = 15;
    private const ITEMS_PER_PAGE_MAX = 100;
    private const ITEMS_PER_PAGE_MIN = 1;
    private const WINDOW_SIZE = 5;

    public function buildControlState(int $page, int $pageCount, int $size, int $total): array
    {
        return [
            'page_size_selector' => $this->resolvePageSize($size),
            'active_page' => $this->clampPage($page, $pageCount),
            'total_pages' => $pageCount,
            'total_records' => $total,
            'has_more_pages' => $page < $pageCount,
            'has_previous_pages' => $page > 1,
            'on_first_page' => $page === 1,
            'on_last_page' => $page === $pageCount || $pageCount === 0,
            'page_window' => $this->computeWindow($page, $pageCount),
            'links' => $this->generatePageLinks($page, $pageCount),
        ];
    }

    public function buildPageSizeDropdown(): array
    {
        return [
            ['value' => 10, 'label' => '10 items'],
            ['value' => 15, 'label' => '15 items'],
            ['value' => 25, 'label' => '25 items'],
            ['value' => 50, 'label' => '50 items'],
            ['value' => 100, 'label' => '100 items'],
        ];
    }

    public function buildPageButtons(int $activePage, int $totalPages): array
    {
        $window = $this->computeWindow($activePage, $totalPages);
        $buttons = [];

        foreach ($window as $pageNum) {
            $buttons[] = [
                'page' => $pageNum,
                'is_active' => $pageNum === $activePage,
                'is_separator' => $pageNum === 0,
            ];
        }

        return $buttons;
    }

    public function buildBreadcrumbs(int $activePage, int $totalPages): array
    {
        return [
            'first_url' => $activePage > 1 ? $this->makeUrl(1) : null,
            'prev_url' => $activePage > 1 ? $this->makeUrl($activePage - 1) : null,
            'next_url' => $activePage < $totalPages ? $this->makeUrl($activePage + 1) : null,
            'last_url' => $activePage < $totalPages ? $this->makeUrl($totalPages) : null,
        ];
    }

    public function buildCounter(int $page, int $size, int $total): array
    {
        $first = min(($page - 1) * $size + 1, $total);
        $last = min($page * $size, $total);

        return [
            'range_start' => $first,
            'range_end' => $last,
            'total_count' => $total,
            'display_text' => "Displaying {$first}-{$last} of {$total} results",
        ];
    }

    public function buildPageJumper(int $page, int $totalPages): array
    {
        $options = [];

        for ($i = 1; $i <= $totalPages; $i++) {
            $options[] = [
                'value' => $i,
                'label' => "Go to {$i}",
                'selected' => $i === $page,
            ];
        }

        return [
            'selected_page' => $page,
            'total_pages' => $totalPages,
            'options' => $options,
        ];
    }

    private function resolvePageSize(int $size): int
    {
        if ($size < self::ITEMS_PER_PAGE_MIN) {
            return self::ITEMS_PER_PAGE_DEFAULT;
        }

        if ($size > self::ITEMS_PER_PAGE_MAX) {
            return self::ITEMS_PER_PAGE_MAX;
        }

        return $size;
    }

    private function clampPage(int $page, int $total): int
    {
        if ($page < 1) {
            return 1;
        }

        if ($total > 0 && $page > $total) {
            return $total;
        }

        return $page;
    }

    private function computeWindow(int $current, int $total): array
    {
        if ($total === 0) {
            return [];
        }

        $half = (int) floor(self::WINDOW_SIZE / 2);
        $windowStart = max(1, $current - $half);
        $windowEnd = min($total, $current + $half);

        if ($windowEnd - $windowStart < self::WINDOW_SIZE - 1) {
            if ($windowStart === 1) {
                $windowEnd = min($total, $windowStart + self::WINDOW_SIZE - 1);
            } else {
                $windowStart = max(1, $windowEnd - self::WINDOW_SIZE + 1);
            }
        }

        $result = [];

        if ($windowStart > 1) {
            $result[] = 1;

            if ($windowStart > 2) {
                $result[] = 0;
            }
        }

        for ($i = $windowStart; $i <= $windowEnd; $i++) {
            $result[] = $i;
        }

        if ($windowEnd < $total) {
            if ($windowEnd < $total - 1) {
                $result[] = 0;
            }

            $result[] = $total;
        }

        return $result;
    }

    private function generatePageLinks(int $page, int $total): array
    {
        return [
            'first' => $page > 1 ? $this->makeUrl(1) : null,
            'previous' => $page > 1 ? $this->makeUrl($page - 1) : null,
            'next' => $page < $total ? $this->makeUrl($page + 1) : null,
            'last' => $page < $total ? $this->makeUrl($total) : null,
        ];
    }

    private function makeUrl(int $page): string
    {
        return '?page=' . $page;
    }
}
