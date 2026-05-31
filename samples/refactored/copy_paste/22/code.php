<?php

declare(strict_types=1);

namespace App\Services\Security;

final class CsrfTokenConfig
{
    public readonly int $tokenBytes;
    public readonly string $hashAlgorithm;
    public readonly int $maxAge;
    public readonly string $sessionKey;
    public readonly string $headerName;

    public function __construct(
        int $tokenBytes = 32,
        string $hashAlgorithm = 'sha256',
        int $maxAge = 7200,
        string $sessionKey = '_csrf_token',
        string $headerName = 'X-CSRF-TOKEN'
    ) {
        $this->tokenBytes = $tokenBytes;
        $this->hashAlgorithm = $hashAlgorithm;
        $this->maxAge = $maxAge;
        $this->sessionKey = $sessionKey;
        $this->headerName = $headerName;
    }
}

final class CsrfTokenService
{
    private CsrfTokenConfig $config;

    public function __construct(CsrfTokenConfig $config)
    {
        $this->config = $config;
    }

    public function generate(): string
    {
        $entropy = random_bytes($this->config->tokenBytes);
        $timestamp = time();
        $encoded = bin2hex($entropy);
        $signature = hash($this->config->hashAlgorithm, $encoded . $timestamp);

        return "{$timestamp}-{$encoded}-{$signature}";
    }

    public function validate(string $token, string $stored): void
    {
        if (!hash_equals($stored, $token)) {
            throw new \InvalidArgumentException('CSRF token mismatch');
        }

        if ($this->isExpired($token)) {
            throw new \InvalidArgumentException('CSRF token expired');
        }
    }

    public function isExpired(string $token): bool
    {
        $parts = explode('-', $token);

        if (count($parts) !== 3) {
            return true;
        }

        $timestamp = (int) $parts[0];

        return (time() - $timestamp) > $this->config->maxAge;
    }
}
