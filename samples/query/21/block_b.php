<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class OrderRepository
{
    public function findActiveById(int $id): ?Order
    {
        return Order::query()
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'voided')
            ->first();
    }

    public function findActiveByOrderNumber(string $orderNumber): ?Order
    {
        return Order::query()
            ->where('order_number', '=', $orderNumber)
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'voided')
            ->first();
    }

    public function getActiveOrders(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = Order::query()
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'voided');

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', '=', $filters['customer_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', '=', $filters['status']);
        }

        if (!empty($filters['payment_status'])) {
            $query->where('payment_status', '=', $filters['payment_status']);
        }

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('order_number', 'LIKE', $searchTerm)
                  ->orWhere('customer_name', 'LIKE', $searchTerm);
            });
        }

        if (!empty($filters['total_min'])) {
            $query->where('total_amount', '>=', $filters['total_min']);
        }

        if (!empty($filters['total_max'])) {
            $query->where('total_amount', '<=', $filters['total_max']);
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
            ->select(['id', 'order_number', 'customer_id', 'status', 'total_amount', 'created_at'])
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

    public function getActiveOrderIdsByStatus(string $status): array
    {
        return Order::query()
            ->where('status', '=', $status)
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'voided')
            ->pluck('id')
            ->toArray();
    }

    public function countActiveByPaymentStatus(string $paymentStatus): int
    {
        return Order::query()
            ->where('payment_status', '=', $paymentStatus)
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'voided')
            ->count();
    }

    public function void(int $id, string $reason): bool
    {
        return DB::table('orders')
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->update([
                'status' => 'voided',
                'void_reason' => $reason,
                'voided_at' => now(),
                'updated_at' => now(),
            ]) > 0;
    }
}
