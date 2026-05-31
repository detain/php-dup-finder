<?php

declare(strict_types=1);

namespace App\Tokens;

use App\Models\Application;
use App\Exceptions\TokenCreationException;

final class ApplicationKeyCreator
{
    private const KEY_PREFIX = 'appkey_';
    private const KEY_BYTES = 32;
    private const HASHING = 'sha256';
    private const SCHEMA_VERSION = '2';

    public function create(Application $app): string
    {
        $prefix = $this->makePrefix($app->id);
        $randomness = $this->makeRandomness();
        $verifyCode = $this->makeVerifyCode($prefix, $randomness);

        return $prefix . $randomness . $verifyCode;
    }

    public function createViewerKey(Application $app): string
    {
        $prefix = $this->makePrefix($app->id, 'viewer');
        $randomness = $this->makeRandomness();
        $verifyCode = $this->makeVerifyCode($prefix, $randomness);

        return $prefix . $randomness . $verifyCode;
    }

    public function createEditorKey(Application $app): string
    {
        $prefix = $this->makePrefix($app->id, 'editor');
        $randomness = $this->makeRandomness();
        $verifyCode = $this->makeVerifyCode($prefix, $randomness);

        return $prefix . $randomness . $verifyCode;
    }

    public function createPublisherKey(Application $app): string
    {
        $prefix = $this->makePrefix($app->id, 'publisher');
        $randomness = $this->makeRandomness();
        $verifyCode = $this->makeVerifyCode($prefix, $randomness);

        return $prefix . $randomness . $verifyCode;
    }

    public function createSubscriberKey(Application $app, string $plan): string
    {
        $prefix = $this->makePrefix($app->id, 'subscriber');
        $randomness = $this->makeRandomness();
        $planHash = hash(self::HASHING, $plan);
        $verifyCode = $this->makeVerifyCode($prefix, $randomness, $planHash);

        return $prefix . $randomness . $verifyCode;
    }

    public function createDeveloperKey(Application $app, array $permissions): string
    {
        $prefix = $this->makePrefix($app->id, 'developer');
        $randomness = $this->makeRandomness();
        $permissionsHash = hash(self::HASHING, json_encode($permissions));
        $verifyCode = $this->makeVerifyCode($prefix, $randomness, $permissionsHash);

        return $prefix . $randomness . $verifyCode;
    }

    public function createRateLimitedKey(Application $app, int $quota): string
    {
        $prefix = $this->makePrefix($app->id, 'ratelimited');
        $randomness = $this->makeRandomness();
        $quotaHash = hash(self::HASHING, (string) $quota);
        $verifyCode = $this->makeVerifyCode($prefix, $randomness, $quotaHash);

        return $prefix . $randomness . $verifyCode;
    }

    public function createExpiringKey(Application $app, \DateTimeInterface $expiresAt): string
    {
        $prefix = $this->makePrefix($app->id, 'expiring');
        $randomness = $this->makeRandomness();
        $expiryHash = hash(self::HASHING, (string) $expiresAt->getTimestamp());
        $verifyCode = $this->makeVerifyCode($prefix, $randomness, $expiryHash);

        return $prefix . $randomness . $verifyCode;
    }

    private function makePrefix(int $appId, string $variant = 'default'): string
    {
        $code = match ($variant) {
            'viewer' => 'vi',
            'editor' => 'ed',
            'publisher' => 'pu',
            'subscriber' => 'su',
            'developer' => 'dv',
            'ratelimited' => 'rl',
            'expiring' => 'ex',
            default => 'df',
        };

        return self::KEY_PREFIX . self::SCHEMA_VERSION . $code . $appId . '_';
    }

    private function makeRandomness(): string
    {
        return bin2hex(random_bytes(self::KEY_BYTES));
    }

    private function makeVerifyCode(string $prefix, string $randomness, string $extra = ''): string
    {
        $toHash = $prefix . $randomness . $extra . self::HASHING;

        return substr(hash(self::HASHING, $toHash), 0, 8);
    }

    public function hash(string $token): string
    {
        return hash(self::HASHING, $token);
    }

    public function check(string $token, string $hashedValue): bool
    {
        return hash_equals($hashedValue, $this->hash($token));
    }

    public function parseOwnerId(string $token): ?int
    {
        if (!str_starts_with($token, self::KEY_PREFIX)) {
            return null;
        }

        $segments = explode('_', $token);

        if (count($segments) < 3) {
            return null;
        }

        return (int) filter_var($segments[2], FILTER_SANITIZE_NUMBER_INT);
    }

    public function parseVariant(string $token): ?string
    {
        if (!str_starts_with($token, self::KEY_PREFIX)) {
            return null;
        }

        if (preg_match('/appkey_2([a-z]{2})/', $token, $found)) {
            return $found[1];
        }

        return 'df';
    }
}
