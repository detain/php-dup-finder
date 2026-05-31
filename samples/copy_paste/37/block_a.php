<?php

declare(strict_types=1);

namespace App\Catalog;

final class IsbnValidator
{
    public function isValidIsbn10(string $isbn): bool
    {
        $clean = $this->stripHyphens($isbn);

        if (strlen($clean) !== 10) {
            return false;
        }

        return $this->validateIsbn10Checksum($clean);
    }

    public function isValidIsbn13(string $isbn): bool
    {
        $clean = $this->stripHyphens($isbn);

        if (strlen($clean) !== 13) {
            return false;
        }

        return $this->validateIsbn13Checksum($clean);
    }

    public function isValidIsbn(string $isbn): bool
    {
        $clean = $this->stripHyphens($isbn);
        $len = strlen($clean);

        if ($len === 10) {
            return $this->validateIsbn10Checksum($clean);
        }

        if ($len === 13) {
            return $this->validateIsbn13Checksum($clean);
        }

        return false;
    }

    public function normalizeIsbn10(string $isbn): string
    {
        $clean = $this->stripHyphens($isbn);

        if (strlen($clean) !== 10) {
            throw new \InvalidArgumentException('Invalid ISBN-10 format');
        }

        return $this->computeIsbn10CheckDigit($clean);
    }

    public function normalizeIsbn13(string $isbn): string
    {
        $clean = $this->stripHyphens($isbn);

        if (strlen($clean) !== 13) {
            throw new \InvalidArgumentException('Invalid ISBN-13 format');
        }

        return $this->computeIsbn13CheckDigit($clean);
    }

    public function convertIsbn10ToIsbn13(string $isbn10): string
    {
        $clean = $this->stripHyphens($isbn10);

        if (!$this->isValidIsbn10($clean)) {
            throw new \InvalidArgumentException('Invalid ISBN-10');
        }

        $base = '978' . substr($clean, 0, 9);

        return $base . $this->computeIsbn13CheckDigit($base);
    }

    public function convertIsbn13ToIsbn10(string $isbn13): string
    {
        $clean = $this->stripHyphens($isbn13);

        if (!$this->isValidIsbn13($clean)) {
            throw new \InvalidArgumentException('Invalid ISBN-13');
        }

        if (substr($clean, 0, 3) !== '978') {
            throw new \InvalidArgumentException('Cannot convert non-978 ISBN-13 to ISBN-10');
        }

        $base = substr($clean, 3, 9);

        return $base . $this->computeIsbn10CheckDigit($base);
    }

    public function formatIsbn(string $isbn, string $separator = '-'): string
    {
        $clean = $this->stripHyphens($isbn);
        $len = strlen($clean);

        if ($len === 10) {
            return $this->formatIsbn10($clean, $separator);
        }

        if ($len === 13) {
            return $this->formatIsbn13($clean, $separator);
        }

        throw new \InvalidArgumentException('Invalid ISBN length');
    }

    private function validateIsbn10Checksum(string $isbn): bool
    {
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $digit = (int) $isbn[$i];

            if ($digit < 0 || $digit > 9) {
                return false;
            }

            $sum += $digit * (10 - $i);
        }

        $checkChar = $isbn[9];
        $checkDigit = $checkChar === 'X' ? 10 : (int) $checkChar;

        if ($checkDigit < 0 || $checkDigit > 10) {
            return false;
        }

        return ($sum + $checkDigit) % 11 === 0;
    }

    private function validateIsbn13Checksum(string $isbn): bool
    {
        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $isbn[$i];

            if ($digit < 0 || $digit > 9) {
                return false;
            }

            $multiplier = ($i % 2 === 0) ? 1 : 3;
            $sum += $digit * $multiplier;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return (int) $isbn[12] === $checkDigit;
    }

    private function computeIsbn10CheckDigit(string $isbn): string
    {
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $isbn[$i] * (10 - $i);
        }

        $remainder = $sum % 11;
        $checkDigit = (11 - $remainder) % 11;

        return $checkDigit === 10 ? 'X' : (string) $checkDigit;
    }

    private function computeIsbn13CheckDigit(string $isbn): string
    {
        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $multiplier = ($i % 2 === 0) ? 1 : 3;
            $sum += (int) $isbn[$i] * $multiplier;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return (string) $checkDigit;
    }

    private function formatIsbn10(string $isbn, string $sep): string
    {
        return substr($isbn, 0, 1) . $sep
            . substr($isbn, 1, 4) . $sep
            . substr($isbn, 5, 4) . $sep
            . substr($isbn, 9, 1);
    }

    private function formatIsbn13(string $isbn, string $sep): string
    {
        return substr($isbn, 0, 3) . $sep
            . substr($isbn, 3, 1) . $sep
            . substr($isbn, 4, 6) . $sep
            . substr($isbn, 10, 3);
    }

    private function stripHyphens(string $isbn): string
    {
        return str_replace(['-', ' '], '', $isbn);
    }
}
