<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Models\Client;
use App\Exceptions\ApiKeyException;

final class SecureKeyFactory
{
    private const PREFIX = 'sk_';
    private const BYTES = 32;
    private const ALGO = 'sha256';
    private const VERSION_BYTE = '1';

    public function createKey(Client $client): string
    {
        $prefixPart = $this->assemblePrefix($client->client_id);
        $entropy = $this->produceEntropy();
        $verification = $this->produceVerification($prefixPart, $entropy);

        return $prefixPart . $entropy . $verification;
    }

    public function createReadOnlyKey(Client $client): string
    {
        $prefixPart = $this->assemblePrefix($client->client_id, 'readonly');
        $entropy = $this->produceEntropy();
        $verification = $this->produceVerification($prefixPart, $entropy);

        return $prefixPart . $entropy . $verification;
    }

    public function createReadWriteKey(Client $client): string
    {
        $prefixPart = $this->assemblePrefix($client->client_id, 'readwrite');
        $entropy = $this->produceEntropy();
        $verification = $this->produceVerification($prefixPart, $entropy);

        return $prefixPart . $entropy . $verification;
    }

    public function createAdminKey(Client $client): string
    {
        $prefixPart = $this->assemblePrefix($client->client_id, 'admin');
        $entropy = $this->produceEntropy();
        $verification = $this->produceVerification($prefixPart, $entropy);

        return $prefixPart . $entropy . $verification;
    }

    public function createIntegrationKey(Client $client, string $integrationId): string
    {
        $prefixPart = $this->assemblePrefix($client->client_id, 'integration');
        $entropy = $this->produceEntropy();
        $integrationHash = hash(self::ALGO, $integrationId);
        $verification = $this->produceVerification($prefixPart, $entropy, $integrationHash);

        return $prefixPart . $entropy . $verification;
    }

    public function createAutomationKey(Client $client, array $triggers): string
    {
        $prefixPart = $this->assemblePrefix($client->client_id, 'automation');
        $entropy = $this->produceEntropy();
        $triggersHash = hash(self::ALGO, implode(',', $triggers));
        $verification = $this->produceVerification($prefixPart, $entropy, $triggersHash);

        return $prefixPart . $entropy . $verification;
    }

    public function createLimitedKey(Client $client, int $maxRequests): string
    {
        $prefixPart = $this->assemblePrefix($client->client_id, 'limited');
        $entropy = $this->produceEntropy();
        $limitHash = hash(self::ALGO, (string) $maxRequests);
        $verification = $this->produceVerification($prefixPart, $entropy, $limitHash);

        return $prefixPart . $entropy . $verification;
    }

    public function createTimeBoundKey(Client $client, \DateTime $expiry): string
    {
        $prefixPart = $this->assemblePrefix($client->client_id, 'timebound');
        $entropy = $this->produceEntropy();
        $expiryStamp = $expiry->getTimestamp();
        $expiryHash = hash(self::ALGO, (string) $expiryStamp);
        $verification = $this->produceVerification($prefixPart, $entropy, $expiryHash);

        return $prefixPart . $entropy . $verification;
    }

    private function assemblePrefix(int $clientId, string $variant = 'standard'): string
    {
        $variantCode = match ($variant) {
            'readonly' => 'ro',
            'readwrite' => 'rw',
            'admin' => 'ad',
            'integration' => 'in',
            'automation' => 'au',
            'limited' => 'li',
            'timebound' => 'tb',
            default => 'st',
        };

        return self::PREFIX . self::VERSION_BYTE . $variantCode . $clientId . '_';
    }

    private function produceEntropy(): string
    {
        return bin2hex(random_bytes(self::BYTES));
    }

    private function produceVerification(string $prefix, string $entropy, string $extra = ''): string
    {
        $data = $prefix . $entropy . $extra . self::ALGO;

        return substr(hash(self::ALGO, $data), 0, 8);
    }

    public function hashKey(string $key): string
    {
        return hash(self::ALGO, $key);
    }

    public function validateKey(string $key, string $hash): bool
    {
        return hash_equals($hash, $this->hashKey($key));
    }

    public function extractClientFromKey(string $key): ?int
    {
        if (!str_starts_with($key, self::PREFIX)) {
            return null;
        }

        $chunks = explode('_', $key);

        if (count($chunks) < 3) {
            return null;
        }

        return (int) filter_var($chunks[2], FILTER_SANITIZE_NUMBER_INT);
    }

    public function extractVariant(string $key): ?string
    {
        if (!str_starts_with($key, self::PREFIX)) {
            return null;
        }

        $pattern = '/sk_1([a-z]{2})/';

        if (preg_match($pattern, $key, $matches)) {
            return $matches[1];
        }

        return 'st';
    }
}
