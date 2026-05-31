<?php

declare(strict_types=1);

namespace App\Services\Pagination;

final class PaginationConfig
{
    public readonly int $defaultSize;
    public readonly int $maxSize;
    public readonly int $minSize;
    public readonly int $windowSize;

    public function __construct(
        int $defaultSize = 15,
        int $maxSize = 100,
        int $minSize = 1,
        int $windowSize = 5
    ) {
        $this->defaultSize = $defaultSize;
        $this->maxSize = $maxSize;
        $this->minSize = $minSize;
        $this->windowSize = $windowSize;
    }
}

final class PaginationService
{
    private PaginationConfig $config;

    public function __construct(PaginationConfig $config)
    {
        $this->config = $config;
    }

    public function build(int $page, int $totalPages, int $size, int $total): array
    {
        $page = $this->bound($page, $totalPages);
        $size = $this->normalizeSize($size);

        return [
            'page_size' => $size,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_previous' => $page > 1,
            'pages_in_window' => $this->buildWindow($page, $totalPages),
        ];
    }

    private function bound(int $page, int $max): int
    {
        if ($page < 1) {
            return 1;
        }

        return $max > 0 ? min($page, $max) : 1;
    }

    private function normalizeSize(int $size): int
    {
        if ($size < $this->config->minSize) {
            return $this->config->defaultSize;
        }

        return min($size, $this->config->maxSize);
    }

    private function buildWindow(int $current, int $total): array
    {
        if ($total === 0) {
            return [];
        }

        $half = (int) floor($this->config->windowSize / 2);
        $start = max(1, $current - $half);
        $end = min($total, $current + $half);

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

        if ($end < $total) {
            if ($end < $total - 1) {
                $pages[] = 0;
            }
            $pages[] = $total;
        }

        return $pages;
    }
}
