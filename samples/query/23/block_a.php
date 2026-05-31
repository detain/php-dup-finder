<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

final class AuditLogService
{
    public function getAuditLogs(array $filters = []): array
    {
        $query = DB::table('audit_logs')
            ->where('deleted_at', '=', null);

        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', '=', $filters['entity_type']);
        }

        if (!empty($filters['entity_id'])) {
            $query->where('entity_id', '=', $filters['entity_id']);
        }

        if (!empty($filters['action'])) {
            $query->where('action', '=', $filters['action']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', '=', $filters['user_id']);
        }

        if (!empty($filters['user_email'])) {
            $query->where('user_email', 'LIKE', '%' . $filters['user_email'] . '%');
        }

        if (!empty($filters['ip_address'])) {
            $query->where('ip_address', '=', $filters['ip_address']);
        }

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

        $perPage = $filters['per_page'] ?? 50;
        $page = $filters['page'] ?? 1;
        $offset = ($page - 1) * $perPage;

        $sortField = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        $total = $query->count();
        $items = $query
            ->select(['id', 'entity_type', 'entity_id', 'action', 'user_id', 'user_email', 'ip_address', 'user_agent', 'created_at'])
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

    public function getAuditLogsByEntity(string $entityType, int $entityId, array $filters = []): array
    {
        $query = DB::table('audit_logs')
            ->where('entity_type', '=', $entityType)
            ->where('entity_id', '=', $entityId)
            ->where('deleted_at', '=', null);

        if (!empty($filters['action'])) {
            $query->where('action', '=', $filters['action']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', '=', $filters['user_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        $perPage = $filters['per_page'] ?? 50;
        $page = $filters['page'] ?? 1;
        $offset = ($page - 1) * $perPage;

        $total = $query->count();
        $items = $query
            ->select(['id', 'action', 'user_id', 'user_email', 'ip_address', 'old_values', 'new_values', 'created_at'])
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

    public function getAuditLogsByUser(int $userId, array $filters = []): array
    {
        $query = DB::table('audit_logs')
            ->where('user_id', '=', $userId)
            ->where('deleted_at', '=', null);

        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', '=', $filters['entity_type']);
        }

        if (!empty($filters['action'])) {
            $query->where('action', '=', $filters['action']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        $perPage = $filters['per_page'] ?? 50;
        $page = $filters['page'] ?? 1;
        $offset = ($page - 1) * $perPage;

        $total = $query->count();
        $items = $query
            ->select(['id', 'entity_type', 'entity_id', 'action', 'ip_address', 'created_at'])
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

    public function getAuditLogsByDateRange(string $dateFrom, string $dateTo, array $filters = []): array
    {
        $query = DB::table('audit_logs')
            ->where('created_at', '>=', $dateFrom)
            ->where('created_at', '<=', $dateTo)
            ->where('deleted_at', '=', null);

        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', '=', $filters['entity_type']);
        }

        if (!empty($filters['action'])) {
            $query->where('action', '=', $filters['action']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', '=', $filters['user_id']);
        }

        $perPage = $filters['per_page'] ?? 50;
        $page = $filters['page'] ?? 1;
        $offset = ($page - 1) * $perPage;

        $total = $query->count();
        $items = $query
            ->select(['id', 'entity_type', 'entity_id', 'action', 'user_id', 'user_email', 'created_at'])
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

    public function countByAction(string $action, ?string $dateFrom = null, ?string $dateTo = null): int
    {
        $query = DB::table('audit_logs')
            ->where('action', '=', $action)
            ->where('deleted_at', '=', null);

        if ($dateFrom !== null) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->count();
    }

    public function countByUserAndAction(int $userId, string $action, ?string $dateFrom = null, ?string $dateTo = null): int
    {
        $query = DB::table('audit_logs')
            ->where('user_id', '=', $userId)
            ->where('action', '=', $action)
            ->where('deleted_at', '=', null);

        if ($dateFrom !== null) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->count();
    }

    public function getRecentActivity(int $limit = 10): Collection
    {
        return DB::table('audit_logs')
            ->where('deleted_at', '=', null)
            ->select(['id', 'entity_type', 'entity_id', 'action', 'user_email', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
