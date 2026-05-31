<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;

abstract class AbstractLogService
{
    protected abstract function getTableName(): string;

    public function getLogs(array $filters = []): array
    {
        $query = DB::table($this->getTableName())
            ->where('deleted_at', '=', null);

        $query = $this->applyFilters($query, $filters);

        $sortField = $filters['sort_by'] ?? $this->getDefaultSortField();
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        $perPage = $filters['per_page'] ?? 50;
        $page = $filters['page'] ?? 1;
        $offset = ($page - 1) * $perPage;

        $total = $query->count();
        $items = $query
            ->select($this->getSelectColumns())
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
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }

    protected function applyFilters($query, array $filters): mixed
    {
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['date_after'])) {
            $query->where('created_at', '>', $filters['date_after']);
        }

        if (!empty($filters['date_before'])) {
            $query->where('created_at', '<', $filters['date_before']);
        }

        return $query;
    }

    abstract protected function getDefaultSortField(): string;
    abstract protected function getSelectColumns(): array;
}
