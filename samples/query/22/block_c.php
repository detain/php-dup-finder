<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ProjectRepository
{
    public function findActiveById(int $id): ?Project
    {
        return Project::query()
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'active')
            ->where('start_date', '<=', now())
            ->whereRaw('(end_date IS NULL OR end_date > ?)', [now()])
            ->first();
    }

    public function findActiveByCode(string $code): ?Project
    {
        return Project::query()
            ->where('code', '=', $code)
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'active')
            ->where('start_date', '<=', now())
            ->whereRaw('(end_date IS NULL OR end_date > ?)', [now()])
            ->first();
    }

    public function getActiveProjects(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = Project::query()
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'active')
            ->where('start_date', '<=', now())
            ->whereRaw('(end_date IS NULL OR end_date > ?)', [now()]);

        if (!empty($filters['client_id'])) {
            $query->where('client_id', '=', $filters['client_id']);
        }

        if (!empty($filters['department'])) {
            $query->where('department', '=', $filters['department']);
        }

        if (!empty($filters['priority'])) {
            $query->where('priority', '=', $filters['priority']);
        }

        if (!empty($filters['assigned_to'])) {
            $query->whereHas('assignments', function ($q) use ($filters) {
                $q->where('user_id', '=', $filters['assigned_to']);
            });
        }

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', $searchTerm)
                  ->orWhere('code', 'LIKE', $searchTerm)
                  ->orWhere('description', 'LIKE', $searchTerm);
            });
        }

        if (!empty($filters['budget_min'])) {
            $query->where('budget', '>=', $filters['budget_min']);
        }

        if (!empty($filters['budget_max'])) {
            $query->where('budget', '<=', $filters['budget_max']);
        }

        if (!empty($filters['start_after'])) {
            $query->where('start_date', '>=', $filters['start_after']);
        }

        if (!empty($filters['start_before'])) {
            $query->where('start_date', '<=', $filters['start_before']);
        }

        $sortField = $filters['sort_by'] ?? 'start_date';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        $allowedSortFields = ['start_date', 'end_date', 'name', 'budget', 'priority', 'created_at'];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'start_date';
        }

        $total = $query->count();
        $offset = ($page - 1) * $perPage;

        $items = $query
            ->select(['id', 'name', 'code', 'client_id', 'department', 'priority', 'budget', 'start_date', 'end_date', 'status'])
            ->with(['client:id,name', 'department_info:id,name'])
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

    public function getActiveProjectsByClient(int $clientId, int $limit = 10): Collection
    {
        return Project::query()
            ->where('client_id', '=', $clientId)
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'active')
            ->where('start_date', '<=', now())
            ->whereRaw('(end_date IS NULL OR end_date > ?)', [now()])
            ->orderBy('start_date', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getProjectsByDepartment(string $department, int $limit = 20): Collection
    {
        return Project::query()
            ->where('department', '=', $department)
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'active')
            ->where('start_date', '<=', now())
            ->whereRaw('(end_date IS NULL OR end_date > ?)', [now()])
            ->orderBy('priority', 'asc')
            ->orderBy('start_date', 'desc')
            ->limit($limit)
            ->get();
    }

    public function updateProgress(int $id, float $percentComplete): bool
    {
        return DB::table('projects')
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'active')
            ->update([
                'percent_complete' => $percentComplete,
                'updated_at' => now(),
            ]) > 0;
    }

    public function countActiveByDepartment(string $department): int
    {
        return Project::query()
            ->where('department', '=', $department)
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'active')
            ->where('start_date', '<=', now())
            ->whereRaw('(end_date IS NULL OR end_date > ?)', [now()])
            ->count();
    }
}
