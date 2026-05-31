<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait SoftDeletesWithActiveFilter
{
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->whereNotIn('status', $this->getInactiveStatuses());
    }

    public function scopeWithFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($searchTerm) {
                foreach ($this->getSearchableColumns() as $column) {
                    $q->orWhere($column, 'LIKE', $searchTerm);
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
            ->orderBy('created_at', 'desc')
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

    abstract protected function getInactiveStatuses(): array;
    abstract protected function getSearchableColumns(): array;
    abstract protected function getSelectColumns(): array;
}
