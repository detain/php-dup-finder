<?php

declare(strict_types=1);

namespace App\Services\Identity;

final class UniqueIdConfig
{
    public readonly int $uuidVersion;
    public readonly string $prefix;
    public readonly string $algorithm;

    public function __construct(
        int $uuidVersion = 4,
        string $prefix = 'id_',
        string $algorithm = 'sha256'
    ) {
        $this->uuidVersion = $uuidVersion;
        $this->prefix = $prefix;
        $this->algorithm = $algorithm;
    }
}

final class UniqueIdService
{
    private UniqueIdConfig $config;

    public function __construct(UniqueIdConfig $config)
    {
        $this->config = $config;
    }

    public function generateUuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr(ord($bytes[6]) & 0x0f | $this->config->uuidVersion << 4);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4)
        );
    }

    public function generatePrefixed(string $prefix): string
    {
        return $prefix . $this->generateUuid();
    }

    public function generateTimestamped(): string
    {
        $timestamp = (int) (microtime(true) * 1000);
        $entropy = bin2hex(random_bytes(16));

        return $this->config->prefix . dechex($timestamp) . $entropy;
    }

    public function generateAlphanumeric(int $length = 16): string
    {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $result;
    }

    public function generateHashed(string $input): string
    {
        return substr(hash($this->config->algorithm, $input . microtime(true)), 0, 16);
    }
}
