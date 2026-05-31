<?php
declare(strict_types=1);

namespace App\Http;

final class RetryPolicy
{
    public const MAX_ATTEMPTS = 3;

    public static function executeWithRetry(callable $operation): array
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;
            $result = $operation($attempt);
            if ($result['success'] ?? false) {
                return $result + ['attempts' => $attempt];
            }
            $lastError = $result['error'] ?? 'unknown';
            if ($attempt < self::MAX_ATTEMPTS) {
                sleep((int)pow(2, $attempt));
            }
        }

        return [
            'success'  => false,
            'attempts' => self::MAX_ATTEMPTS,
            'error'    => $lastError,
        ];
    }
}

namespace App\Webhooks;

use App\Http\RetryPolicy;

final class StripeWebhookDispatcher
{
    public static function dispatch(string $url, array $payload): array
    {
        return RetryPolicy::executeWithRetry(function () use ($url, $payload) {
            return ['success' => true, 'status' => 200];
        });
    }
}

namespace App\Integrations\Shopify;

use App\Http\RetryPolicy;

final class ShopifyApiCaller
{
    public static function get(string $shop, string $endpoint): array
    {
        return RetryPolicy::executeWithRetry(fn() => ['success' => true, 'data' => []]);
    }
}

namespace App\Mailgun;

use App\Http\RetryPolicy;

final class MailgunSender
{
    public static function send(string $from, string $to, string $subject): bool
    {
        $r = RetryPolicy::executeWithRetry(fn() => ['success' => true]);
        return $r['success'];
    }
}
