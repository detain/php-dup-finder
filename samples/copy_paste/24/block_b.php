<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Exceptions\IdentifierException;

final class IdentifierFactory
{
    private const ULID_VERSION = 4;
    private const PREFIX = 'id_';

    public function makeUuid(): string
    {
        $uuid = $this->produceUuidV4();

        if (!$this->isUuidFormat($uuid)) {
            throw new IdentifierException('UUID generation produced invalid result');
        }

        return $uuid;
    }

    public function makePrefixedUuid(string $prefix): string
    {
        $uuid = $this->produceUuidV4();

        if (!$this->isUuidFormat($uuid)) {
            throw new IdentifierException('UUID generation produced invalid result');
        }

        return $prefix . $uuid;
    }

    public function makeTimestamped(): string
    {
        $epoch = $this->currentTimeMillis();
        $entropy = $this->randomHex(16);

        return self::PREFIX . $epoch . $entropy;
    }

    public function makeSequential(string $nodePrefix): string
    {
        $counter = $this->incrementCounter();
        $node = $this->randomHex(6);
        $timestamp = dechex($this->currentTimeMillis());

        return $nodePrefix . $timestamp . sprintf('%08x', $counter) . $node;
    }

    public function makeAlphabetic(int $length = 12): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $index = random_int(0, strlen($alphabet) - 1);
            $result .= $album[$index];
        }

        return $result;
    }

    public function makeNumeric(int $digits = 16): string
    {
        $result = '';

        for ($i = 0; $i < $digits; $i++) {
            $result .= (string) random_int(0, 9);
        }

        return $result;
    }

    public function makeKSortable(): string
    {
        $timestamp = $this->currentTimeMillis();
        $random = $this->randomHex(10);

        return $this->formatKSortable($timestamp, $random);
    }

    public function makeShort(int $length = 6): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $result;
    }

    public function makeUlid(): string
    {
        $timeComponent = $this->encodeBase32($this->currentTimeMillis(), 10);
        $randomComponent = $this->encodeBase32($this->randomLong(16), 16);

        return $timeComponent . $randomComponent;
    }

    public function makeHashed(string $content, string $pepper = ''): string
    {
        $toHash = $content . $pepper . microtime(true);

        return substr(hash('sha256', $toHash), 0, 16);
    }

    private function produceUuidV4(): string
    {
        $data = random_bytes(16);

        $data[6] = chr(ord($data[6]) & 0x0f | ULID_VERSION << 4);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function isUuidFormat(string $uuid): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    private function currentTimeMillis(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    private function randomLong(int $bytes): int
    {
        return gmp_intval(gmp_init(bin2hex(random_bytes($bytes)), 16));
    }

    private function incrementCounter(): int
    {
        static $counter = 0;

        return ++$counter;
    }

    private function formatKSortable(int $timestamp, string $random): string
    {
        return sprintf('%013x-%s', $timestamp, $random);
    }

    private function encodeBase32(int $value, int $length): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result = $alphabet[$value & 0x1f] . $result;
            $value >>= 5;
        }

        return $result;
    }
}
