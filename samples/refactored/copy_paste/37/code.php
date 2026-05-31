<?php

namespace App\Services\Catalog;

final class IsbnConfig
{
    public readonly bool $allowHyphens;
    public readonly bool $autoConvert;

    public function __construct(bool $allowHyphens = true, bool $autoConvert = false)
    {
        $this->allowHyphens = $allowHyphens;
        $this->autoConvert = $autoConvert;
    }
}

final class IsbnService
{
    private IsbnConfig $config;

    public function __construct(IsbnConfig $config)
    {
        $this->config = $config;
    }

    public function validate(string $isbn): bool
    {
        $clean = $this->strip($isbn);
        $len = strlen($clean);

        if ($len === 10) {
            return $this->validateIsbn10($clean);
        }

        if ($len === 13) {
            return $this->validateIsbn13($clean);
        }

        return false;
    }

    public function convert(string $isbn): string
    {
        $clean = $this->strip($isbn);

        if (strlen($clean) === 10 && $this->validateIsbn10($clean)) {
            return $this->toIsbn13($clean);
        }

        if (strlen($clean) === 13 && $this->validateIsbn13($clean)) {
            return $this->toIsbn10($clean);
        }

        throw new \InvalidArgumentException('Invalid ISBN for conversion');
    }

    private function validateIsbn10(string $isbn): bool
    {
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $isbn[$i] * (10 - $i);
        }

        $check = $isbn[9] === 'X' ? 10 : (int) $isbn[9];

        return ($sum + $check) % 11 === 0;
    }

    private function validateIsbn13(string $isbn): bool
    {
        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $isbn[$i] * (($i % 2 === 0) ? 1 : 3);
        }

        $check = (10 - ($sum % 10)) % 10;

        return (int) $isbn[12] === $check;
    }

    private function toIsbn13(string $isbn10): string
    {
        $base = '978' . substr($isbn10, 0, 9);
        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $base[$i] * (($i % 2 === 0) ? 1 : 3);
        }

        $check = (10 - ($sum % 10)) % 10;

        return $base . $check;
    }

    private function toIsbn10(string $isbn13): string
    {
        $base = substr($isbn13, 3, 9);
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $base[$i] * (10 - $i);
        }

        $remainder = $sum % 11;
        $check = (11 - $remainder) % 11;

        return $base . ($check === 10 ? 'X' : (string) $check);
    }

    private function strip(string $isbn): string
    {
        return preg_replace('/[\s-]+/', '', $isbn) ?? $isbn;
    }
}
