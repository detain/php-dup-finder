<?php

declare(strict_types=1);

namespace App\Identifiers;

use App\Exceptions\IdGenerationException;

final class UniqueIdGenerator
{
    private const UUID_VERSION = 4;
    private const ID_PREFIX = 'uid_';
    private const TIMESTAMPED_PREFIX = 'ts_';

    public function generateUuid(): string
    {
        $uuid = $this->createUuidV4();

        if (!$this->isValidUuid($uuid)) {
            throw new IdGenerationException('Generated UUID is invalid');
        }

        return $uuid;
    }

    public function generatePrefixedUuid(string $prefix): string
    {
        $uuid = $this->createUuidV4();

        if (!$this->isValidUuid($uuid)) {
            throw new IdGenerationException('Generated UUID is invalid');
        }

        return $prefix . $uuid;
    }

    public function generateTimestampedId(): string
    {
        $timestamp = $this->getCurrentTimestamp();
        $random = $this->generateRandomComponent(16);

        return self::TIMESTAMPED_PREFIX . $timestamp . $random;
    }

    public function generateNumericId(int $maxDigits = 18): string
    {
        $bytes = (int) ceil($maxDigits / 2);

        do {
            $randomBytes = random_bytes($bytes);
            $numeric = $this->bytesToNumeric($randomBytes, $maxDigits);
        } while ($this->hasLeadingZeros($numeric) || $this->hasCollision($numeric));

        return $numeric;
    }

    public function generateAlphanumericId(int $length = 16): string
    {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $randomIndex = $this->getSecureRandomIndex(strlen($characters));
            $result .= $characters[$randomIndex];
        }

        if ($this->hasCollision($result)) {
            return $this->generateAlphanumericId($length);
        }

        return $result;
    }

    public function generateOrderedUuid(string $nodeId = null): string
    {
        $timestamp = $this->getCurrentTimestampHex();
        $random = $this->generateRandomComponent(10);
        $node = $nodeId ?? $this->generateNodeId();

        return $timestamp . $random . $node;
    }

    public function generateShortId(int $length = 8): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $id = '';

        for ($i = 0; $i < $length; $i++) {
            $randomIndex = $this->getSecureRandomIndex(strlen($alphabet));
            $id .= $alphabet[$randomIndex];
        }

        return $id;
    }

    public function generateUlid(): string
    {
        $timestamp = $this->getCurrentTimestamp();
        $random = $this->generateRandomComponent(16);

        return $this->encodeCrockford($timestamp) . $this->encodeCrockford($random);
    }

    public function generateHashedId(string $input, string $salt = null): string
    {
        $toHash = $input . ($salt ?? '') . microtime(true);
        $hash = hash('sha256', $toHash);

        return substr($hash, 0, 16);
    }

    private function createUuidV4(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr(ord($bytes[6]) & 0x0f | self::UUID_VERSION << 4);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function isValidUuid(string $uuid): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    private function getCurrentTimestamp(): int
    {
        return (int) (microtime(true) * 1000);
    }

    private function getCurrentTimestampHex(): string
    {
        return dechex($this->getCurrentTimestamp());
    }

    private function generateRandomComponent(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    private function generateNodeId(): string
    {
        $bytes = random_bytes(6);
        return bin2hex($bytes);
    }

    private function bytesToNumeric(string $bytes, int $maxDigits): string
    {
        $hex = bin2hex($bytes);
        $numeric = gmp_strval(gmp_init($hex, 16), 10);

        if (strlen($numeric) > $maxDigits) {
            return substr($numeric, -$maxDigits);
        }

        return str_pad($numeric, $maxDigits, '0', STR_PAD_LEFT);
    }

    private function hasLeadingZeros(string $numericId): bool
    {
        return strlen($numericId) !== strlen((string) (int) $numericId);
    }

    private function hasCollision(string $id): bool
    {
        return false;
    }

    private function getSecureRandomIndex(int $max): int
    {
        return random_int(0, $max - 1);
    }

    private function encodeCrockford(string $data): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        $result = '';
        $hex = hex2bin($data);

        foreach (str_split($hex) as $byte) {
            $result .= $alphabet[ord($byte) & 0x1f];
        }

        return $result;
    }
}
