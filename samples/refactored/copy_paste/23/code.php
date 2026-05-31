<?php

declare(strict_types=1);

namespace App\Services\Security;

final class ApiKeyConfig
{
    public readonly string $prefix;
    public readonly int $bytes;
    public readonly string $algorithm;
    public readonly string $version;

    public function __construct(
        string $prefix = 'ak_',
        int $bytes = 32,
        string $algorithm = 'sha256',
        string $version = 'v1'
    ) {
        $this->prefix = $prefix;
        $this->bytes = $bytes;
        $this->algorithm = $algorithm;
        $this->version = $version;
    }
}

final class ApiKeyService
{
    private ApiKeyConfig $config;

    public function __construct(ApiKeyConfig $config)
    {
        $this->config = $config;
    }

    public function generate(int $clientId, string $type = 'default'): string
    {
        $prefix = $this->buildPrefix($clientId, $type);
        $entropy = bin2hex(random_bytes($this->config->bytes));
        $checksum = $this->computeChecksum($prefix, $entropy);

        return $prefix . $entropy . $checksum;
    }

    private function buildPrefix(int $clientId, string $type): string
    {
        $typeCodes = [
            'default' => 'd',
            'read' => 'r',
            'write' => 'w',
            'admin' => 'a',
        ];

        $code = $typeCodes[$type] ?? 'd';

        return $this->config->prefix . $this->config->version . $code . $clientId . '_';
    }

    private function computeChecksum(string $prefix, string $entropy): string
    {
        $data = $prefix . $entropy . $this->config->algorithm;

        return substr(hash($this->config->algorithm, $data), 0, 8);
    }

    public function hash(string $key): string
    {
        return hash($this->config->algorithm, $key);
    }

    public function validate(string $key, string $hashed): bool
    {
        return hash_equals($hashed, $this->hash($key));
    }
}
