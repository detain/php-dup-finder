<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use App\Models\Queries\ActiveRecordQueryBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class UserRepository
{
    public function findActiveById(int $id): ?User
    {
        return User::query()
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'suspended')
            ->where('status', '!=', 'banned')
            ->first();
    }

    public function findActiveByEmail(string $email): ?User
    {
        return User::query()
            ->where('email', '=', $email)
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'suspended')
            ->where('status', '!=', 'banned')
            ->first();
    }

    public function getActiveUsers(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = User::query()
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'suspended')
            ->where('status', '!=', 'banned');

        if (!empty($filters['role'])) {
            $query->where('role', '=', $filters['role']);
        }

        if (!empty($filters['department'])) {
            $query->where('department', '=', $filters['department']);
        }

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', $searchTerm)
                  ->orWhere('email', 'LIKE', $searchTerm);
            });
        }

        if (!empty($filters['created_after'])) {
            $query->where('created_at', '>=', $filters['created_after']);
        }

        if (!empty($filters['created_before'])) {
            $query->where('created_at', '<=', $filters['created_before']);
        }

        $total = $query->count();
        $offset = ($page - 1) * $perPage;

        $items = $query
            ->select(['id', 'name', 'email', 'role', 'department', 'created_at'])
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

    public function getActiveUserIdsByRole(string $role): array
    {
        return User::query()
            ->where('role', '=', $role)
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'suspended')
            ->where('status', '!=', 'banned')
            ->pluck('id')
            ->toArray();
    }

    public function countActiveByDepartment(string $department): int
    {
        return User::query()
            ->where('department', '=', $department)
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'suspended')
            ->where('status', '!=', 'banned')
            ->count();
    }

    public function restore(int $id): bool
    {
        return DB::table('users')
            ->where('id', '=', $id)
            ->where('deleted_at', '!=', null)
            ->update([
                'deleted_at' => null,
                'is_active' => true,
                'status' => 'active',
                'updated_at' => now(),
            ]) > 0;
    }
}
