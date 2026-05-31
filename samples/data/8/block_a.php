<?php
declare(strict_types=1);

namespace App\Catalog\Repositories;

use App\Catalog\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ProductRepository
{
    public function findActive(int $productId): ?Product
    {
        return Cache::remember(
            "product:active:{$productId}",
            3600,
            function () use ($productId) {
                $row = DB::table('products')
                    ->where('id', $productId)
                    ->where('active', true)
                    ->first();

                if ($row === null) {
                    return null;
                }

                return new Product(
                    id:    (int)$row->id,
                    sku:   (string)$row->sku,
                    name:  (string)$row->name,
                    price: (float)$row->price,
                );
            }
        );
    }

    public function popularInCategory(int $categoryId, int $limit = 20): array
    {
        return Cache::remember(
            "products:popular:cat:{$categoryId}:{$limit}",
            3600,
            function () use ($categoryId, $limit) {
                $rows = DB::table('products')
                    ->where('category_id', $categoryId)
                    ->where('active', true)
                    ->orderByDesc('sales_count')
                    ->limit($limit)
                    ->get();

                $products = [];
                foreach ($rows as $row) {
                    $products[] = new Product(
                        id:    (int)$row->id,
                        sku:   (string)$row->sku,
                        name:  (string)$row->name,
                        price: (float)$row->price,
                    );
                }
                return $products;
            }
        );
    }

    public function invalidate(int $productId): void
    {
        Cache::forget("product:active:{$productId}");
    }
}
