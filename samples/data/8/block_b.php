<?php
declare(strict_types=1);

namespace App\Pricing\Repositories;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class PromotionRepository
{
    public function activePromotions(): array
    {
        return Cache::remember(
            'promotions:active:all',
            3600,
            function () {
                $rows = DB::table('promotions')
                    ->where('active', true)
                    ->where('starts_at', '<=', now())
                    ->where('ends_at', '>=', now())
                    ->orderBy('priority', 'desc')
                    ->get();

                $promos = [];
                foreach ($rows as $row) {
                    $promos[] = [
                        'id'          => (int)$row->id,
                        'code'        => (string)$row->code,
                        'discount'    => (float)$row->discount,
                        'kind'        => (string)$row->kind,
                        'min_order'   => (float)$row->min_order,
                    ];
                }
                return $promos;
            }
        );
    }

    public function findByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        return Cache::remember(
            "promo:code:{$code}",
            3600,
            function () use ($code) {
                $row = DB::table('promotions')
                    ->where('code', $code)
                    ->where('active', true)
                    ->first();

                if ($row === null) {
                    return null;
                }

                return [
                    'id'        => (int)$row->id,
                    'code'      => $row->code,
                    'discount'  => (float)$row->discount,
                    'expires'   => $row->ends_at,
                ];
            }
        );
    }
}
