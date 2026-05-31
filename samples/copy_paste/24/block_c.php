<?php

declare(strict_types=1);

namespace App\Entities;

use App\Exceptions\UniqueIdException;

final class GlobalIdFactory
{
    private const UUID_VARIANT = 4;
    private const ID_PREFIX = 'gid_';

    public function generateUuid(): string
    {
        $uuid = $this->assembleUuidV4();

        if (!$this->matchesUuidPattern($uuid)) {
            throw new UniqueIdException('UUID does not conform to RFC 4122');
        }

        return $uuid;
    }

    public function generatePrefixed(string $prefix): string
    {
        $uuid = $this->assembleUuidV4();

        if (!$this->matchesUuidPattern($uuid)) {
            throw new UniqueIdException('UUID does not conform to RFC 4122');
        }

        return $prefix . $uuid;
    }

    public function generateTimebased(): string
    {
        $milliseconds = $this->currentTime();
        $entropy = $this->produceEntropy(16);

        return self::ID_PREFIX . dechex($milliseconds) . $entropy;
    }

    public function generateInteger(int $digits = 15): string
    {
        $bytes = (int) ceil($digits / 2);
        $hex = bin2hex(random_bytes($bytes));
        $integer = gmp_strval(gmp_init($hex, 16), 10);

        return str_pad(substr($integer, -$digits), $digits, '0', STR_PAD_LEFT);
    }

    public function generateAlpha(int $length = 14): string
    {
        $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $id = '';

        for ($i = 0; $i < $length; $i++) {
            $position = random_int(0, strlen($charset) - 1);
            $id .= $charset[$position];
        }

        return $id;
    }

    public function generateSortable(): string
    {
        $time = $this->currentTime();
        $random = $this->produceEntropy(10);
        $node = $this->produceEntropy(6);

        return sprintf('%013x-%s-%s', $time, $random, $node);
    }

    public function generateCompact(int $length = 8): string
    {
        $alphanumerics = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $id = '';

        for ($i = 0; $i < $length; $i++) {
            $id .= $alphanumerics[random_int(0, strlen($alphanumerics) - 1)];
        }

        return $id;
    }

    public function generateCrockford(): string
    {
        $timestamp = $this->currentTime();
        $random = $this->produceEntropy(16);

        return $this->toCrockfordBase32($timestamp) . $this->toCrockfordBase32(hexdec($random));
    }

    public function generateSalted(string $input): string
    {
        $salt = $this->produceEntropy(16);
        $hash = hash('sha256', $input . $salt);

        return substr($hash, 0, 16);
    }

    private function assembleUuidV4(): string
    {
        $data = random_bytes(16);

        $data[6] = chr(ord($data[6]) & 0x0f | self::UUID_VARIANT << 4);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function matchesUuidPattern(string $uuid): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    private function currentTime(): int
    {
        return (int) (microtime(true) * 1000);
    }

    private function produceEntropy(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    private function toCrockfordBase32(int $value): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $result = '';

        do {
            $result = $alphabet[$value & 0x1f] . $result;
            $value >>= 5;
        } while ($value > 0);

        return $result;
    }
}
