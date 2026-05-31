<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class PaginationHelper
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;
    private const MIN_PAGE_SIZE = 1;
    private const DEFAULT_SORT_FIELD = 'created_at';
    private const DEFAULT_SORT_DIRECTION = 'desc';
    private const ALLOWED_SORT_FIELDS = ['id', 'name', 'created_at', 'updated_at', 'email'];
    private const ALLOWED_SORT_DIRECTIONS = ['asc', 'desc'];
    private const FILTER_OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'like', 'in'];
    private const DEFAULT_FILTER_MODE = 'and';
    private const MAX_FILTER_COUNT = 20;
    private const SEARCH_MIN_LENGTH = 2;
    private const SEARCH_MAX_LENGTH = 100;

    public function paginate(
        Builder $query,
        int $page = 1,
        ?int $perPage = null,
        ?string $sortField = null,
        ?string $sortDirection = null
    ): array {
        $perPage = $this->normalizePageSize($perPage);
        $page = $this->normalizePageNumber($page);
        $sortField = $this->normalizeSortField($sortField);
        $sortDirection = $this->normalizeSortDirection($sortDirection);

        $total = $query->count();
        $offset = ($page - 1) * $perPage;
        $lastPage = (int) ceil($total / $perPage);

        $items = $query
            ->orderBy($sortField, $sortDirection)
            ->offset($offset)
            ->limit($perPage)
            ->get();

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $offset + 1,
                'to' => min($offset + $perPage, $total),
                'sort_field' => $sortField,
                'sort_direction' => $sortDirection,
            ],
        ];
    }

    public function paginateWithFilters(
        Builder $query,
        array $filters,
        int $page = 1,
        ?int $perPage = null,
        ?string $sortField = null,
        ?string $sortDirection = null,
        ?string $search = null
    ): array {
        $query = $this->applyFilters($query, $filters);

        if ($search !== null) {
            $query = $this->applySearch($query, $search);
        }

        return $this->paginate($query, $page, $perPage, $sortField, $sortDirection);
    }

    public function applyFilters(Builder $query, array $filters): Builder
    {
        $filterCount = 0;

        foreach ($filters as $field => $filter) {
            if ($filterCount >= self::MAX_FILTER_COUNT) {
                break;
            }

            if (is_array($filter)) {
                $operator = $filter['operator'] ?? 'eq';
                $value = $filter['value'];
            } else {
                $operator = 'eq';
                $value = $filter;
            }

            $query = $this->applyFilter($query, $field, $operator, $value);
            $filterCount++;
        }

        return $query;
    }

    public function applyFilter(Builder $query, string $field, string $operator, mixed $value): Builder
    {
        if (!in_array($operator, self::FILTER_OPERATORS, true)) {
            return $query;
        }

        return match ($operator) {
            'eq' => $query->where($field, '=', $value),
            'ne' => $query->where($field, '!=', $value),
            'gt' => $query->where($field, '>', $value),
            'gte' => $query->where($field, '>=', $value),
            'lt' => $query->where($field, '<', $value),
            'lte' => $query->where($field, '<=', $value),
            'like' => $query->where($field, 'LIKE', '%' . $value . '%'),
            'in' => $query->whereIn($field, is_array($value) ? $value : [$value]),
            default => $query,
        };
    }

    public function applySearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if (strlen($search) < self::SEARCH_MIN_LENGTH) {
            return $query;
        }

        if (strlen($search) > self::SEARCH_MAX_LENGTH) {
            $search = substr($search, 0, self::SEARCH_MAX_LENGTH);
        }

        $terms = preg_split('/\s+/', $search);

        return $query->where(function ($q) use ($terms) {
            foreach ($terms as $index => $term) {
                $condition = $index === 0 ? 'where' : 'orWhere';

                $q->{$condition}('name', 'LIKE', '%' . $term . '%')
                  ->orWhere('email', 'LIKE', '%' . $term . '%')
                  ->orWhere('description', 'LIKE', '%' . $term . '%');
            }
        });
    }

    public function sort(Builder $query, ?string $sortField, ?string $sortDirection): Builder
    {
        $sortField = $this->normalizeSortField($sortField);
        $sortDirection = $this->normalizeSortDirection($sortDirection);

        return $query->orderBy($sortField, $sortDirection);
    }

    private function normalizePageSize(?int $perPage): int
    {
        if ($perPage === null) {
            return self::DEFAULT_PAGE_SIZE;
        }

        return max(self::MIN_PAGE_SIZE, min($perPage, self::MAX_PAGE_SIZE));
    }

    private function normalizePageNumber(int $page): int
    {
        return max(1, $page);
    }

    private function normalizeSortField(?string $sortField): string
    {
        if ($sortField === null) {
            return self::DEFAULT_SORT_FIELD;
        }

        if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
            return self::DEFAULT_SORT_FIELD;
        }

        return $sortField;
    }

    private function normalizeSortDirection(?string $sortDirection): string
    {
        if ($sortDirection === null) {
            return self::DEFAULT_SORT_DIRECTION;
        }

        $sortDirection = strtolower($sortDirection);

        if (!in_array($sortDirection, self::ALLOWED_SORT_DIRECTIONS, true)) {
            return self::DEFAULT_SORT_DIRECTION;
        }

        return $sortDirection;
    }

    public function getDefaultPageSize(): int
    {
        return self::DEFAULT_PAGE_SIZE;
    }

    public function getMaxPageSize(): int
    {
        return self::MAX_PAGE_SIZE;
    }
}
