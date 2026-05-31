<?php
declare(strict_types=1);

namespace Acme\Infrastructure\Config;

final class EnvReader
{
    /** @var array<string,string|false> */
    private array $env;

    public function __construct()
    {
        $this->env = $_ENV + $_SERVER + getenv();
    }

    public function string(string $key, string $default): string
    {
        $value = $this->env[$key] ?? false;
        if ($value === false || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    public function port(string $key, int $default): int
    {
        $port = (int) $this->string($key, (string) $default);
        if ($port <= 0 || $port > 65535) {
            throw new \RuntimeException("{$key} out of range: {$port}");
        }
        return $port;
    }

    public function positiveInt(string $key, int $default): int
    {
        $val = (int) $this->string($key, (string) $default);
        if ($val < 1) {
            throw new \RuntimeException("{$key} must be >= 1");
        }
        return $val;
    }
}

final class DatabaseConfigFactory
{
    public function __construct(private readonly EnvReader $env)
    {
    }

    public function create(): array
    {
        return [
            'host' => $this->env->string('DB_HOST', 'localhost'),
            'port' => $this->env->port('DB_PORT', 5432),
            'user' => $this->env->string('DB_USER', 'app'),
            'password' => $this->env->string('DB_PASS', ''),
            'timeout' => $this->env->positiveInt('DB_TIMEOUT', 30),
            'database' => $this->env->string('DB_NAME', 'acme'),
        ];
    }
}
