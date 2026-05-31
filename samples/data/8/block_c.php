<?php
declare(strict_types=1);

namespace App\Geo\Repositories;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class CityRepository
{
    public function lookupByPostalCode(string $postalCode): ?array
    {
        $postalCode = strtoupper(preg_replace('/\s+/', '', $postalCode));
        if (!preg_match('/^[A-Z0-9]{3,10}$/', $postalCode)) {
            return null;
        }

        return Cache::remember(
            "geo:postal:{$postalCode}",
            3600,
            function () use ($postalCode) {
                $row = DB::table('postal_codes')
                    ->where('code', $postalCode)
                    ->first();

                if ($row !== null) {
                    return [
                        'city'    => (string)$row->city,
                        'region'  => (string)$row->region,
                        'country' => (string)$row->country,
                    ];
                }

                $response = Http::get('https://geo.example.com/lookup', [
                    'postal' => $postalCode,
                ]);

                if (!$response->successful()) {
                    return null;
                }

                $data = $response->json();
                DB::table('postal_codes')->insert([
                    'code'    => $postalCode,
                    'city'    => $data['city']    ?? '',
                    'region'  => $data['region']  ?? '',
                    'country' => $data['country'] ?? '',
                ]);

                return [
                    'city'    => $data['city']    ?? '',
                    'region'  => $data['region']  ?? '',
                    'country' => $data['country'] ?? '',
                ];
            }
        );
    }
}
