<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;

final class SessionTokenConfig
{
    public readonly int $tokenBytes;
    public readonly string $hashAlgorithm;
    public readonly int $gracePeriod;

    public function __construct(
        int $tokenBytes = 32,
        string $hashAlgorithm = 'sha256',
        int $gracePeriod = 300
    ) {
        $this->tokenBytes = $tokenBytes;
        $this->hashAlgorithm = $hashAlgorithm;
        $this->gracePeriod = $gracePeriod;
    }
}

final class SessionTokenService
{
    private SessionTokenConfig $config;

    public function __construct(SessionTokenConfig $config)
    {
        $this->config = $config;
    }

    public function regenerate(User $user, bool $longLived = false): string
    {
        $this->invalidateCurrentSession();

        $token = $this->generate($user->id);

        $this->store($token, $user->id, $longLived);

        return $token;
    }

    private function generate(int $userId): string
    {
        $entropy = random_bytes($this->config->tokenBytes);
        $material = implode('|', [$userId, time(), bin2hex($entropy)]);

        return hash($this->config->hashAlgorithm, $material);
    }

    private function invalidateCurrentSession(): void
    {
        Session::flush();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }

    private function store(string $token, int $userId, bool $longLived): void
    {
        Session::put('auth_token', $token);
        Session::put('auth_user_id', $userId);
        Session::put('auth_issued_at', time());

        if ($longLived) {
            Session::put('long_lived', true);
        }
    }
}
