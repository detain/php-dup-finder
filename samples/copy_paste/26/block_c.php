<?php

declare(strict_types=1);

namespace App\Tables\Pagination;

final class PaginationDisplayManager
{
    private const DEFAULT_SIZE = 15;
    private const MAX_SIZE = 100;
    private const MIN_SIZE = 1;
    private const VISIBLE_PAGES = 5;

    public function prepare(int $current, int $totalPages, int $size, int $totalRecords): array
    {
        return [
            'page_size' => $this->sanitizePageSize($size),
            'current' => $this->boundPage($current, $totalPages),
            'pages_total' => $totalPages,
            'records_total' => $totalRecords,
            'can_go_next' => $current < $totalPages,
            'can_go_previous' => $current > 1,
            'is_first_page' => $current === 1,
            'is_last_page' => $current === $totalPages || $totalPages === 0,
            'visible_range' => $this->determineVisibleRange($current, $totalPages),
            'links' => $this->generateLinkSet($current, $totalPages),
        ];
    }

    public function availableSizes(): array
    {
        return [
            ['value' => 10, 'text' => '10 rows'],
            ['value' => 15, 'text' => '15 rows'],
            ['value' => 25, 'text' => '25 rows'],
            ['value' => 50, 'text' => '50 rows'],
            ['value' => 100, 'text' => '100 rows'],
        ];
    }

    public function pageLinks(int $current, int $total): array
    {
        $range = $this->determineVisibleRange($current, $total);
        $links = [];

        foreach ($range as $page) {
            $links[] = [
                'page' => $page,
                'active' => $page === $current,
                'placeholder' => $page === 0,
            ];
        }

        return $links;
    }

    public function navigationLinks(int $current, int $total): array
    {
        return [
            'first' => $current > 1 ? $this->constructUrl(1) : null,
            'previous' => $current > 1 ? $this->constructUrl($current - 1) : null,
            'next' => $current < $total ? $this->constructUrl($current + 1) : null,
            'last' => $current < $total ? $this->constructUrl($total) : null,
        ];
    }

    public function rangeDisplay(int $current, int $size, int $total): array
    {
        $start = min(($current - 1) * $size + 1, $total);
        $end = min($current * $size, $total);

        return [
            'start' => $start,
            'end' => $end,
            'total' => $total,
            'message' => "{$start} - {$end} of {$total}",
        ];
    }

    public function jumpToOptions(int $current, int $total): array
    {
        $options = [];

        for ($p = 1; $p <= $total; $p++) {
            $options[] = [
                'value' => $p,
                'label' => "Page {$p}",
                'selected' => $p === $current,
            ];
        }

        return [
            'current' => $current,
            'total' => $total,
            'options' => $options,
        ];
    }

    private function sanitizePageSize(int $size): int
    {
        if ($size < self::MIN_SIZE) {
            return self::DEFAULT_SIZE;
        }

        if ($size > self::MAX_SIZE) {
            return self::MAX_SIZE;
        }

        return $size;
    }

    private function boundPage(int $page, int $max): int
    {
        if ($page < 1) {
            return 1;
        }

        if ($max > 0 && $page > $max) {
            return $max;
        }

        return $page;
    }

    private function determineVisibleRange(int $current, int $total): array
    {
        if ($total === 0) {
            return [];
        }

        $halfWindow = (int) floor(self::VISIBLE_PAGES / 2);
        $from = max(1, $current - $halfWindow);
        $to = min($total, $current + $halfWindow);

        if ($to - $from < self::VISIBLE_PAGES - 1) {
            if ($from === 1) {
                $to = min($total, $from + self::VISIBLE_PAGES - 1);
            } else {
                $from = max(1, $to - self::VISIBLE_PAGES + 1);
            }
        }

        $pages = [];

        if ($from > 1) {
            $pages[] = 1;

            if ($from > 2) {
                $pages[] = 0;
            }
        }

        for ($i = $from; $i <= $to; $i++) {
            $pages[] = $i;
        }

        if ($to < $total) {
            if ($to < $total - 1) {
                $pages[] = 0;
            }

            $pages[] = $total;
        }

        return $pages;
    }

    private function generateLinkSet(int $page, int $total): array
    {
        return [
            'first_url' => $page > 1 ? $this->constructUrl(1) : null,
            'prev_url' => $page > 1 ? $this->constructUrl($page - 1) : null,
            'next_url' => $page < $total ? $this->constructUrl($page + 1) : null,
            'last_url' => $page < $total ? $this->constructUrl($total) : null,
        ];
    }

    private function constructUrl(int $page): string
    {
        return '?page=' . $page;
    }
}
