<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait PublishableQueries
{
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('deleted_at', '=', null)
            ->where('is_published', '=', true)
            ->where('publish_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('publish_until')
                  ->orWhere('publish_until', '>', now());
            });
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'published')
            ->where('start_date', '>', now());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'active')
            ->where('start_date', '<=', now())
            ->where(function ($q) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>', now());
            });
    }

    public function scopeWithFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['search'])) {
            $searchColumns = $this->getSearchableColumns();
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($searchTerm, $searchColumns) {
                foreach ($searchColumns as $index => $column) {
                    $condition = $index === 0 ? 'where' : 'orWhere';
                    $q->{$condition}($column, 'LIKE', $searchTerm);
                }
            });
        }

        if (!empty($filters['created_after'])) {
            $query->where('created_at', '>=', $filters['created_after']);
        }

        if (!empty($filters['created_before'])) {
            $query->where('created_at', '<=', $filters['created_before']);
        }

        return $query;
    }

    public function scopePaginated(Builder $query, int $page, int $perPage): array
    {
        $total = $query->count();
        $offset = ($page - 1) * $perPage;

        $items = $query
            ->select($this->getSelectColumns())
            ->orderBy($this->getDefaultSortField(), $this->getDefaultSortDirection())
            ->offset($offset)
            ->limit($perPage)
            ->get();

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }

    abstract protected function getSearchableColumns(): array;
    abstract protected function getSelectColumns(): array;
    abstract protected function getDefaultSortField(): string;
    abstract protected function getDefaultSortDirection(): string;
}
