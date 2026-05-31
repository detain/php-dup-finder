<?php
declare(strict_types=1);

namespace App\Integrations\Shopify;

final class ShopifyApiCaller
{
    public static function get(string $shop, string $endpoint, string $accessToken): array
    {
        $url = "https://{$shop}.myshopify.com/admin/api/2024-01{$endpoint}";

        $attempt = 0;
        $lastResult = null;

        while ($attempt < 3) {
            $attempt++;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => [
                    'X-Shopify-Access-Token: ' . $accessToken,
                    'Accept: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);

            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status === 429) {
                error_log("Shopify rate-limited on attempt {$attempt}");
                $delay = $attempt * 2;
                sleep($delay);
                $lastResult = ['error' => 'rate_limited', 'status' => 429];
                continue;
            }

            if ($status >= 200 && $status < 300 && $body !== false) {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                return [
                    'success'  => true,
                    'attempts' => $attempt,
                    'data'     => $decoded,
                ];
            }

            $lastResult = ['error' => 'http_failure', 'status' => $status];

            if ($attempt < 3) {
                sleep(1);
            }
        }

        return [
            'success'  => false,
            'attempts' => 3,
            'last'     => $lastResult,
        ];
    }
}
