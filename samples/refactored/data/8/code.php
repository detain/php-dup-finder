<?php
declare(strict_types=1);

namespace App\Caching;

final class CacheTtl
{
    public const HOURLY_SECONDS = 3600;
    public const DAILY_SECONDS = 86400;
    public const WEEKLY_SECONDS = 604800;
}

namespace App\Catalog\Repositories;

use App\Caching\CacheTtl;
use App\Catalog\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ProductRepository
{
    public function findActive(int $productId): ?Product
    {
        return Cache::remember(
            "product:active:{$productId}",
            CacheTtl::HOURLY_SECONDS,
            fn() => DB::table('products')->where('id', $productId)->first()
        );
    }
}

namespace App\Pricing\Repositories;

use App\Caching\CacheTtl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class PromotionRepository
{
    public function activePromotions(): array
    {
        return Cache::remember(
            'promotions:active:all',
            CacheTtl::HOURLY_SECONDS,
            fn() => DB::table('promotions')->where('active', true)->get()->all()
        );
    }
}

namespace App\Geo\Repositories;

use App\Caching\CacheTtl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class CityRepository
{
    public function lookupByPostalCode(string $postalCode): ?array
    {
        return Cache::remember(
            "geo:postal:{$postalCode}",
            CacheTtl::HOURLY_SECONDS,
            fn() => DB::table('postal_codes')->where('code', $postalCode)->first()
        );
    }
}
