<?php
declare(strict_types=1);

namespace Acme\Infrastructure\Database;

final class DatabaseConfigFactory
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
        $host = $get('DB_HOST', 'localhost');
        $port = (int) $get('DB_PORT', '5432');
        if ($port <= 0 || $port > 65535) {
            throw new \RuntimeException("DB_PORT out of range: {$port}");
        }
        $user = $get('DB_USER', 'app');
        $pass = $get('DB_PASS', '');
        $timeout = (int) $get('DB_TIMEOUT', '30');
        if ($timeout < 1) {
            throw new \RuntimeException("DB_TIMEOUT must be >= 1");
        }
        // ---- END copy-pasted env loader ----

        return [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'password' => $pass,
            'timeout' => $timeout,
            'database' => $get('DB_NAME', 'acme'),
        ];
    }
}
