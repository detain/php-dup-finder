<?php
declare(strict_types=1);

namespace Acme\Http;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;

final class HttpClientFactory
{
    public const TIMEOUT_SECONDS         = 30;
    public const CONNECT_TIMEOUT_SECONDS = 5;
    public const MAX_RETRIES             = 5;
    public const BACKOFF_BASE_MS         = 250;

    /**
     * @param array<string, mixed> $overrides
     */
    public static function create(string $baseUri, array $overrides = []): Client
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::retry(
            static function (int $retries, Request $req, $resp = null, $err = null): bool {
                if ($retries >= self::MAX_RETRIES) {
                    return false;
                }
                if ($err !== null) {
                    return true;
                }
                return $resp !== null && $resp->getStatusCode() >= 500;
            },
            static fn (int $retries): int => (int) (self::BACKOFF_BASE_MS * (2 ** $retries))
        ));

        $defaults = [
            'base_uri'        => $baseUri,
            'timeout'         => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'handler'         => $stack,
        ];

        return new Client(array_replace_recursive($defaults, $overrides));
    }
}

// Usage:
// $http = HttpClientFactory::create('https://api.stripe.com/v1/', [
//     'headers' => ['Authorization' => 'Bearer ' . $apiKey],
// ]);
