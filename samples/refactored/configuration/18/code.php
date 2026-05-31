<?php

declare(strict_types=1);

namespace App\Infrastructure\Configuration;

use App\Attributes\Configuration;

#[Configuration('pagination')]
final class PaginationConfig
{
    public function __construct(
        public readonly int $defaultPageSize = 20,
        public readonly int $maxPageSize = 100,
        public readonly int $minPageSize = 1,
        public readonly string $defaultSortField = 'created_at',
        public readonly string $defaultSortDirection = 'desc',
        public readonly int $maxFilterCount = 20,
        public readonly int $searchMinLength = 2,
        public readonly int $searchMaxLength = 100,
    ) {}
}

#[Configuration('query')]
final class QueryConfig
{
    public const DEFAULT_PAGE = 1;
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;
    public const MIN_PER_PAGE = 1;
    public const DEFAULT_SORT = '-created_at';
    public const MAX_INCLUDE_DEPTH = 3;
    public const PAGE_RANGE_DELTA = 5;

    public function __construct(
        public readonly int $defaultPage = 1,
        public readonly int $defaultPerPage = 20,
        public readonly int $maxPerPage = 100,
        public readonly int $minPerPage = 1,
        public readonly string $defaultSort = '-created_at',
        public readonly int $maxIncludeDepth = 3,
    ) {}

    public function constrainPageSize(int $perPage): int
    {
        return max($this->minPerPage, min($perPage, $this->maxPerPage));
    }

    public function normalizePage(int $page): int
    {
        return max(1, $page);
    }

    public function calculateOffset(int $page, int $perPage): int
    {
        return ($page - 1) * $perPage;
    }
}

trait HasPagination
{
    protected abstract function getPaginationConfig(): PaginationConfig;

    protected function paginate(Builder $query, int $page, ?int $perPage): array
    {
        $config = $this->getPaginationConfig();
        $perPage = $config->constrainPageSize($perPage ?? $config->defaultPageSize);
        $page = $config->normalizePage($page);

        $total = $query->count();
        $offset = $config->calculateOffset($page, $perPage);

        return [
            'data' => $query->orderBy($config->defaultSortField, $config->defaultSortDirection)
                          ->offset($offset)->limit($perPage)->get(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }
}
