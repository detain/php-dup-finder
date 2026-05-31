<?php
declare(strict_types=1);

namespace Acme\Infrastructure\Queue;

final class QueueConfigFactory
{
    public function create(string $prefix): array
    {
        if ($prefix === '') {
            throw new \InvalidArgumentException("Queue prefix required");
        }

        // ---- BEGIN copy-pasted env loader ----
        $env = $_ENV + $_SERVER + getenv();
        $get = static function (string $key, string $default) use ($env): string {
            $value = $env[$key] ?? false;
            if ($value === false || $value === '') {
                return $default;
            }
            return (string) $value;
        };
        $host = $get('AMQP_HOST', 'localhost');
        $port = (int) $get('AMQP_PORT', '5672');
        if ($port <= 0 || $port > 65535) {
            throw new \RuntimeException("AMQP_PORT out of range: {$port}");
        }
        $user = $get('AMQP_USER', 'guest');
        $pass = $get('AMQP_PASS', 'guest');
        $timeout = (int) $get('AMQP_TIMEOUT', '10');
        if ($timeout < 1) {
            throw new \RuntimeException("AMQP_TIMEOUT must be >= 1");
        }
        // ---- END copy-pasted env loader ----

        return [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'password' => $pass,
            'timeout' => $timeout,
            'vhost' => $prefix,
        ];
    }
}
