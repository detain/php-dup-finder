<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

final class SecurityEventService
{
    public function getSecurityEvents(array $filters = []): array
    {
        $query = DB::table('security_events')
            ->where('deleted_at', '=', null);

        if (!empty($filters['event_type'])) {
            $query->where('event_type', '=', $filters['event_type']);
        }

        if (!empty($filters['severity'])) {
            $query->where('severity', '=', $filters['severity']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', '=', $filters['user_id']);
        }

        if (!empty($filters['ip_address'])) {
            $query->where('ip_address', '=', $filters['ip_address']);
        }

        if (!empty($filters['user_agent'])) {
            $query->where('user_agent', 'LIKE', '%' . $filters['user_agent'] . '%');
        }

        if (!empty($filters['country'])) {
            $query->where('country', '=', $filters['country']);
        }

        if (!empty($filters['failed_login'])) {
            $query->where('failed_login_attempts', '>=', $filters['failed_login']);
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
            ->select(['id', 'event_type', 'severity', 'user_id', 'ip_address', 'country', 'user_agent', 'details', 'created_at'])
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

    public function getSecurityEventsByUser(int $userId, array $filters = []): array
    {
        $query = DB::table('security_events')
            ->where('user_id', '=', $userId)
            ->where('deleted_at', '=', null);

        if (!empty($filters['event_type'])) {
            $query->where('event_type', '=', $filters['event_type']);
        }

        if (!empty($filters['severity'])) {
            $query->where('severity', '=', $filters['severity']);
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
            ->select(['id', 'event_type', 'severity', 'ip_address', 'country', 'details', 'created_at'])
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

    public function getSecurityEventsByIp(string $ipAddress, array $filters = []): array
    {
        $query = DB::table('security_events')
            ->where('ip_address', '=', $ipAddress)
            ->where('deleted_at', '=', null);

        if (!empty($filters['event_type'])) {
            $query->where('event_type', '=', $filters['event_type']);
        }

        if (!empty($filters['severity'])) {
            $query->where('severity', '=', $filters['severity']);
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
            ->select(['id', 'event_type', 'severity', 'user_id', 'country', 'details', 'created_at'])
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

    public function getSecurityEventsByDateRange(string $dateFrom, string $dateTo, array $filters = []): array
    {
        $query = DB::table('security_events')
            ->where('created_at', '>=', $dateFrom)
            ->where('created_at', '<=', $dateTo)
            ->where('deleted_at', '=', null);

        if (!empty($filters['event_type'])) {
            $query->where('event_type', '=', $filters['event_type']);
        }

        if (!empty($filters['severity'])) {
            $query->where('severity', '=', $filters['severity']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', '=', $filters['user_id']);
        }

        $perPage = $filters['per_page'] ?? 50;
        $page = $filters['page'] ?? 1;
        $offset = ($page - 1) * $perPage;

        $total = $query->count();
        $items = $query
            ->select(['id', 'event_type', 'severity', 'user_id', 'ip_address', 'created_at'])
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

    public function countBySeverity(string $severity, ?string $dateFrom = null, ?string $dateTo = null): int
    {
        $query = DB::table('security_events')
            ->where('severity', '=', $severity)
            ->where('deleted_at', '=', null);

        if ($dateFrom !== null) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->count();
    }

    public function countByIpAndType(string $ipAddress, string $eventType, ?string $dateFrom = null, ?string $dateTo = null): int
    {
        $query = DB::table('security_events')
            ->where('ip_address', '=', $ipAddress)
            ->where('event_type', '=', $eventType)
            ->where('deleted_at', '=', null);

        if ($dateFrom !== null) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->count();
    }

    public function getRecentSecurityEvents(int $limit = 10): Collection
    {
        return DB::table('security_events')
            ->where('deleted_at', '=', null)
            ->select(['id', 'event_type', 'severity', 'ip_address', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
