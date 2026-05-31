<?php

declare(strict_types=1);

namespace App\Services\Pagination;

final class PaginationConfig
{
    public readonly int $defaultSize;
    public readonly int $maxSize;
    public readonly int $minSize;
    public readonly int $defaultPage;

    public function __construct(
        int $defaultSize = 15,
        int $maxSize = 100,
        int $minSize = 1,
        int $defaultPage = 1
    ) {
        $this->defaultSize = $defaultSize;
        $this->maxSize = $maxSize;
        $this->minSize = $minSize;
        $this->defaultPage = $defaultPage;
    }
}

final class PaginationService
{
    private PaginationConfig $config;

    public function __construct(PaginationConfig $config)
    {
        $this->config = $config;
    }

    public function paginate(array $items, int $page, int $pageSize): array
    {
        $page = $this->normalizePage($page);
        $pageSize = $this->normalizeSize($pageSize);
        $totalItems = count($items);
        $totalPages = $this->computeTotalPages($totalItems, $pageSize);
        $offset = $this->computeOffset($page, $pageSize);

        $paginatedItems = array_slice($items, $offset, $pageSize);

        return [
            'items' => $paginatedItems,
            'pagination' => [
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'page_size' => $pageSize,
                'has_next' => $page < $totalPages,
                'has_previous' => $page > 1,
            ],
        ];
    }

    private function normalizePage(int $page): int
    {
        return max($this->config->defaultPage, $page);
    }

    private function normalizeSize(int $size): int
    {
        if ($size < $this->config->minSize) {
            return $this->config->defaultSize;
        }

        return min($size, $this->config->maxSize);
    }

    private function computeTotalPages(int $totalItems, int $pageSize): int
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
}
