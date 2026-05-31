<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ProductRepository
{
    public function findActiveById(int $id): ?Product
    {
        return Product::query()
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'discontinued')
            ->where('status', '!=', 'archived')
            ->first();
    }

    public function findActiveBySku(string $sku): ?Product
    {
        return Product::query()
            ->where('sku', '=', $sku)
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'discontinued')
            ->where('status', '!=', 'archived')
            ->first();
    }

    public function getActiveProducts(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = Product::query()
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'discontinued')
            ->where('status', '!=', 'archived');

        if (!empty($filters['category_id'])) {
            $query->where('category_id', '=', $filters['category_id']);
        }

        if (!empty($filters['brand'])) {
            $query->where('brand', '=', $filters['brand']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', '=', $filters['status']);
        }

        if (!empty($filters['in_stock'])) {
            $query->where('inventory_count', '>', 0);
        }

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', $searchTerm)
                  ->orWhere('sku', 'LIKE', $searchTerm)
                  ->orWhere('description', 'LIKE', $searchTerm);
            });
        }

        if (!empty($filters['price_min'])) {
            $query->where('price', '>=', $filters['price_min']);
        }

        if (!empty($filters['price_max'])) {
            $query->where('price', '<=', $filters['price_max']);
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
            ->select(['id', 'sku', 'name', 'category_id', 'price', 'inventory_count', 'created_at'])
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

    public function getActiveProductIdsByCategory(int $categoryId): array
    {
        return Product::query()
            ->where('category_id', '=', $categoryId)
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'discontinued')
            ->where('status', '!=', 'archived')
            ->pluck('id')
            ->toArray();
    }

    public function countActiveByBrand(string $brand): int
    {
        return Product::query()
            ->where('brand', '=', $brand)
            ->where('deleted_at', '=', null)
            ->where('is_active', '=', true)
            ->where('status', '!=', 'discontinued')
            ->where('status', '!=', 'archived')
            ->count();
    }

    public function archive(int $id): bool
    {
        return DB::table('products')
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->update([
                'status' => 'archived',
                'archived_at' => now(),
                'updated_at' => now(),
            ]) > 0;
    }
}
