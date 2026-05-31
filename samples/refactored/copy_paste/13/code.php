<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Entities\User;

final class EmailActionTokenConfig
{
    public readonly string $endpoint;
    public readonly string $hashAlgorithm;
    public readonly int $expiryHours;

    public function __construct(
        string $endpoint = '/verify',
        string $hashAlgorithm = 'sha256',
        int $expiryHours = 24
    ) {
        $this->endpoint = $endpoint;
        $this->hashAlgorithm = $hashAlgorithm;
        $this->expiryHours = $expiryHours;
    }
}

final class EmailActionTokenService
{
    private EmailActionTokenConfig $config;

    public function __construct(EmailActionTokenConfig $config)
    {
        $this->config = $config;
    }

    public function createLink(User $user, string $action, array $extraData = []): string
    {
        $token = $this->generateToken($user, $action, $extraData);
        $expiry = $this->calculateExpiry();

        $payload = array_merge([
            'token' => $token,
            'expiry' => $expiry,
            'user_id' => $user->id,
            'action' => $action,
        ], $extraData);

        $encoded = $this->serialize($payload);

        return $this->buildUrl($encoded);
    }

    private function generateToken(User $user, string $action, array $data): string
    {
        $material = implode('|', array_filter([
            $user->email ?? $user->id,
            $user->id,
            $action,
            time(),
            ...array_values($data),
        ]));

        return hash($this->config->hashAlgorithm, $material);
    }

    private function calculateExpiry(): int
    {
        return time() + ($this->config->expiryHours * 3600);
    }

    private function serialize(array $payload): string
    {
        $json = json_encode($payload);

        if ($json === false) {
            throw new \RuntimeException('Token serialization failed');
        }

        $encoded = base64_encode($json);
        return str_replace(['+', '/'], ['-', '_'], rtrim($encoded, '='));
    }

    private function buildUrl(string $token): string
    {
        return $this->config->endpoint . '?token=' . $token;
    }
}
