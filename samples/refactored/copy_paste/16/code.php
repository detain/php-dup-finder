<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;

final class SecureTokenConfig
{
    public readonly int $bytes;
    public readonly int $defaultLifetime;
    public readonly string $hashAlgorithm;

    public function __construct(
        int $bytes = 32,
        int $defaultLifetime = 3600,
        string $hashAlgorithm = 'sha256'
    ) {
        $this->bytes = $bytes;
        $this->defaultLifetime = $defaultLifetime;
        $this->hashAlgorithm = $hashAlgorithm;
    }
}

final class SecureTokenService
{
    private SecureTokenConfig $config;

    public function __construct(SecureTokenConfig $config)
    {
        $this->config = $config;
    }

    public function generate(User $user, string $purpose, ?int $lifetime = null): string
    {
        $bytes = $this->generateEntropy();
        $now = time();
        $lifetime = $lifetime ?? $this->config->defaultLifetime;

        $payload = [
            'entropy' => $bytes,
            'created' => $now,
            'expires' => $now + $lifetime,
            'identity' => $this->hashIdentity($user),
            'purpose' => $purpose,
        ];

        return $this->serialize($payload);
    }

    private function generateEntropy(): string
    {
        return bin2hex(random_bytes($this->config->bytes));
    }

    private function hashIdentity(User $user): string
    {
        $material = implode('|', array_filter([$user->id, $user->email, $user->password_hash ?? '']));
        return hash($this->config->hashAlgorithm, $material);
    }

    private function serialize(array $payload): string
    {
        $json = json_encode($payload);
        $encoded = base64_encode($json ?? '');
        return str_replace(['+', '/', '='], ['-', '_', ''], $encoded);
    }
}
