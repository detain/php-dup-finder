<?php
declare(strict_types=1);

namespace Acme\Infrastructure\Cache;

final class CacheConfigFactory
{
    public function create(): array
    {
        // ---- BEGIN copy-pasted env loader ----
        $env = $_ENV + $_SERVER + getenv();
        $get = static function (string $key, string $default) use ($env): string {
            $value = $env[$key] ?? false;
            if ($value === false || $value === '') {
                return $default;
            }
            return (string) $value;
        };
        $host = $get('REDIS_HOST', 'localhost');
        $port = (int) $get('REDIS_PORT', '6379');
        if ($port <= 0 || $port > 65535) {
            throw new \RuntimeException("REDIS_PORT out of range: {$port}");
        }
        $user = $get('REDIS_USER', 'default');
        $pass = $get('REDIS_PASS', '');
        $timeout = (int) $get('REDIS_TIMEOUT', '5');
        if ($timeout < 1) {
            throw new \RuntimeException("REDIS_TIMEOUT must be >= 1");
        }
        // ---- END copy-pasted env loader ----

        return [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'password' => $pass,
            'timeout' => $timeout,
            'db' => (int) ($_ENV['REDIS_DB'] ?? 0),
        ];
    }
}
