<?php

declare(strict_types=1);

namespace App\Api\Keys;

use App\Models\ApiClient;
use App\Exceptions\KeyGenerationException;
use Illuminate\Support\Facades\Hash;

final class ApiKeyGenerator
{
    private const KEY_PREFIX = 'ak_';
    private const KEY_LENGTH = 32;
    private const HASH_ALGORITHM = 'sha256';
    private const VERSION = 'v1';

    public function generateForClient(ApiClient $client): string
    {
        $prefix = $this->buildPrefix($client->id);
        $randomComponent = $this->createRandomComponent();
        $checksum = $this->computeChecksum($prefix, $randomComponent);

        return $prefix . $randomComponent . $checksum;
    }

    public function generateReadOnlyKey(ApiClient $client): string
    {
        $prefix = $this->buildPrefix($client->id, 'read');
        $randomComponent = $this->createRandomComponent();
        $checksum = $this->computeChecksum($prefix, $randomComponent);

        return $prefix . $randomComponent . $checksum;
    }

    public function generateWriteKey(ApiClient $client): string
    {
        $prefix = $this->buildPrefix($client->id, 'write');
        $randomComponent = $this->createRandomComponent();
        $checksum = $this->computeChecksum($prefix, $randomComponent);

        return $prefix . $randomComponent . $checksum;
    }

    public function generateAdminKey(ApiClient $client): string
    {
        $prefix = $this->buildPrefix($client->id, 'admin');
        $randomComponent = $this->createRandomComponent();
        $checksum = $this->computeChecksum($prefix, $randomComponent);

        return $prefix . $randomComponent . $checksum;
    }

    public function generateWebhookKey(ApiClient $client, string $webhookUrl): string
    {
        $prefix = $this->buildPrefix($client->id, 'webhook');
        $randomComponent = $this->createRandomComponent();
        $webhookHash = hash(self::HASH_ALGORITHM, $webhookUrl);
        $checksum = $this->computeChecksum($prefix, $randomComponent, $webhookHash);

        return $prefix . $randomComponent . $checksum;
    }

    public function generateServiceKey(ApiClient $client, array $scopes): string
    {
        $prefix = $this->buildPrefix($client->id, 'service');
        $randomComponent = $this->createRandomComponent();
        $scopesHash = hash(self::HASH_ALGORITHM, implode('|', $scopes));
        $checksum = $this->computeChecksum($prefix, $randomComponent, $scopesHash);

        return $prefix . $randomComponent . $checksum;
    }

    public function generateScopedKey(ApiClient $client, array $permissions): string
    {
        $prefix = $this->buildPrefix($client->id, 'scoped');
        $randomComponent = $this->createRandomComponent();
        $permissionsHash = hash(self::HASH_ALGORITHM, json_encode($permissions));
        $checksum = $this->computeChecksum($prefix, $randomComponent, $permissionsHash);

        return $prefix . $randomComponent . $checksum;
    }

    public function generateRotationKey(ApiClient $client): string
    {
        $prefix = $this->buildPrefix($client->id, 'rotation');
        $randomComponent = $this->createRandomComponent();
        $checksum = $this->computeChecksum($prefix, $randomComponent);

        return $prefix . $randomComponent . $checksum;
    }

    public function generateExpiredKey(ApiClient $client, int $expiresAt): string
    {
        $prefix = $this->buildPrefix($client->id, 'temp');
        $randomComponent = $this->createRandomComponent();
        $expiryHash = hash(self::HASH_ALGORITHM, (string) $expiresAt);
        $checksum = $this->computeChecksum($prefix, $randomComponent, $expiryHash);

        return $prefix . $randomComponent . $checksum;
    }

    private function buildPrefix(int $clientId, string $type = 'default'): string
    {
        $typePrefix = match ($type) {
            'read' => 'r',
            'write' => 'w',
            'admin' => 'a',
            'webhook' => 'wh',
            'service' => 's',
            'scoped' => 'sc',
            'rotation' => 'rot',
            'temp' => 't',
            default => 'd',
        };

        return self::KEY_PREFIX . self::VERSION . $typePrefix . $clientId . '_';
    }

    private function createRandomComponent(): string
    {
        return bin2hex(random_bytes(self::KEY_LENGTH));
    }

    private function computeChecksum(string $prefix, string $random, string $additional = ''): string
    {
        $material = $prefix . $random . $additional . self::HASH_ALGORITHM;

        return substr(hash(self::HASH_ALGORITHM, $material), 0, 8);
    }

    public function hashKey(string $plainKey): string
    {
        return hash(self::HASH_ALGORITHM, $plainKey);
    }

    public function verifyKey(string $plainKey, string $hashedKey): bool
    {
        return hash_equals($hashedKey, $this->hashKey($plainKey));
    }

    public function extractClientId(string $key): ?int
    {
        if (!str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }

        $parts = explode('_', $key);

        if (count($parts) < 3) {
            return null;
        }

        return (int) filter_var($parts[2], FILTER_SANITIZE_NUMBER_INT);
    }

    public function extractKeyType(string $key): ?string
    {
        if (!str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }

        $regex = '/ak_v([rwawsrots])/';

        if (preg_match($regex, $key, $matches)) {
            return $matches[1];
        }

        return 'd';
    }
}
