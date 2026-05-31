<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

final class ComplianceLogService
{
    public function getComplianceLogs(array $filters = []): array
    {
        $query = DB::table('compliance_logs')
            ->where('deleted_at', '=', null);

        if (!empty($filters['regulation'])) {
            $query->where('regulation', '=', $filters['regulation']);
        }

        if (!empty($filters['requirement'])) {
            $query->where('requirement', '=', $filters['requirement']);
        }

        if (!empty($filters['status'])) {
            $query->where('compliance_status', '=', $filters['status']);
        }

        if (!empty($filters['risk_level'])) {
            $query->where('risk_level', '=', $filters['risk_level']);
        }

        if (!empty($filters['department'])) {
            $query->where('department', '=', $filters['department']);
        }

        if (!empty($filters['auditor_id'])) {
            $query->where('auditor_id', '=', $filters['auditor_id']);
        }

        if (!empty($filters['data_owner'])) {
            $query->where('data_owner', 'LIKE', '%' . $filters['data_owner'] . '%');
        }

        if (!empty($filters['evidence_provided'])) {
            $query->where('evidence_provided', '=', $filters['evidence_provided']);
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
            ->select(['id', 'regulation', 'requirement', 'compliance_status', 'risk_level', 'department', 'data_owner', 'due_date', 'created_at'])
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

    public function getComplianceLogsByRegulation(string $regulation, array $filters = []): array
    {
        $query = DB::table('compliance_logs')
            ->where('regulation', '=', $regulation)
            ->where('deleted_at', '=', null);

        if (!empty($filters['status'])) {
            $query->where('compliance_status', '=', $filters['status']);
        }

        if (!empty($filters['risk_level'])) {
            $query->where('risk_level', '=', $filters['risk_level']);
        }

        if (!empty($filters['department'])) {
            $query->where('department', '=', $filters['department']);
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
            ->select(['id', 'requirement', 'compliance_status', 'risk_level', 'department', 'due_date', 'last_reviewed_at'])
            ->orderBy('due_date', 'asc')
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

    public function getComplianceLogsByDepartment(string $department, array $filters = []): array
    {
        $query = DB::table('compliance_logs')
            ->where('department', '=', $department)
            ->where('deleted_at', '=', null);

        if (!empty($filters['regulation'])) {
            $query->where('regulation', '=', $filters['regulation']);
        }

        if (!empty($filters['status'])) {
            $query->where('compliance_status', '=', $filters['status']);
        }

        if (!empty($filters['risk_level'])) {
            $query->where('risk_level', '=', $filters['risk_level']);
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
            ->select(['id', 'regulation', 'requirement', 'compliance_status', 'risk_level', 'due_date', 'created_at'])
            ->orderBy('risk_level', 'desc')
            ->orderBy('due_date', 'asc')
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

    public function getComplianceLogsByDateRange(string $dateFrom, string $dateTo, array $filters = []): array
    {
        $query = DB::table('compliance_logs')
            ->where('created_at', '>=', $dateFrom)
            ->where('created_at', '<=', $dateTo)
            ->where('deleted_at', '=', null);

        if (!empty($filters['regulation'])) {
            $query->where('regulation', '=', $filters['regulation']);
        }

        if (!empty($filters['status'])) {
            $query->where('compliance_status', '=', $filters['status']);
        }

        if (!empty($filters['risk_level'])) {
            $query->where('risk_level', '=', $filters['risk_level']);
        }

        $perPage = $filters['per_page'] ?? 50;
        $page = $filters['page'] ?? 1;
        $offset = ($page - 1) * $perPage;

        $total = $query->count();
        $items = $query
            ->select(['id', 'regulation', 'requirement', 'compliance_status', 'risk_level', 'department', 'created_at'])
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

    public function countByStatus(string $status, ?string $dateFrom = null, ?string $dateTo = null): int
    {
        $query = DB::table('compliance_logs')
            ->where('compliance_status', '=', $status)
            ->where('deleted_at', '=', null);

        if ($dateFrom !== null) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->count();
    }

    public function countByRegulationAndStatus(string $regulation, string $status, ?string $dateFrom = null, ?string $dateTo = null): int
    {
        $query = DB::table('compliance_logs')
            ->where('regulation', '=', $regulation)
            ->where('compliance_status', '=', $status)
            ->where('deleted_at', '=', null);

        if ($dateFrom !== null) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->count();
    }

    public function getUpcomingDeadlines(int $limit = 10): Collection
    {
        return DB::table('compliance_logs')
            ->where('deleted_at', '=', null)
            ->where('compliance_status', '!=', 'compliant')
            ->where('due_date', '>=', now())
            ->select(['id', 'regulation', 'requirement', 'department', 'due_date'])
            ->orderBy('due_date', 'asc')
            ->limit($limit)
            ->get();
    }
}
