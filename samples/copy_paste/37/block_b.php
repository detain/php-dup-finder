<?php

declare(strict_types=1);

namespace App\Inventory;

final class BookIdentifierValidator
{
    public function validIsbn10(string $code): bool
    {
        $stripped = $this->removeDashes($code);

        return strlen($stripped) === 10 && $this->checkIsbn10Sum($stripped);
    }

    public function validIsbn13(string $code): bool
    {
        $stripped = $this->removeDashes($code);

        return strlen($stripped) === 13 && $this->checkIsbn13Sum($stripped);
    }

    public function validIsbn(string $code): bool
    {
        $stripped = $this->removeDashes($code);
        $len = strlen($stripped);

        if ($len === 10) {
            return $this->checkIsbn10Sum($stripped);
        }

        if ($len === 13) {
            return $this->checkIsbn13Sum($stripped);
        }

        return false;
    }

    public function generateIsbn10CheckDigit(string $partial): string
    {
        $clean = $this->removeDashes($partial);

        if (strlen($clean) !== 9) {
            throw new \InvalidArgumentException('ISBN-10 check digit requires 9 digits');
        }

        $total = 0;

        for ($i = 0; $i < 9; $i++) {
            $total += (int) $clean[$i] * (10 - $i);
        }

        $mod = $total % 11;
        $digit = (11 - $mod) % 11;

        return $digit === 10 ? 'X' : (string) $digit;
    }

    public function generateIsbn13CheckDigit(string $partial): string
    {
        $clean = $this->removeDashes($partial);

        if (strlen($clean) !== 12) {
            throw new \InvalidArgumentException('ISBN-13 check digit requires 12 digits');
        }

        $total = 0;

        for ($i = 0; $i < 12; $i++) {
            $factor = ($i % 2 === 0) ? 1 : 3;
            $total += (int) $clean[$i] * $factor;
        }

        $digit = (10 - ($total % 10)) % 10;

        return (string) $digit;
    }

    public function migrateToIsbn13(string $isbn10): string
    {
        $clean = $this->removeDashes($isbn10);

        if (!$this->validIsbn10($clean)) {
            throw new \InvalidArgumentException('Invalid ISBN-10 for migration');
        }

        $prefixed = '978' . substr($clean, 0, 9);
        $check = $this->generateIsbn13CheckDigit($prefixed);

        return $prefixed . $check;
    }

    public function migrateToIsbn10(string $isbn13): string
    {
        $clean = $this->removeDashes($isbn13);

        if (!$this->validIsbn13($clean)) {
            throw new \InvalidArgumentException('Invalid ISBN-13 for migration');
        }

        if (substr($clean, 0, 3) !== '978') {
            throw new \InvalidArgumentException('Only 978 prefix can be migrated to ISBN-10');
        }

        $base = substr($clean, 3, 9);
        $check = $this->generateIsbn10CheckDigit($base);

        return $base . $check;
    }

    public function displayFormatted(string $isbn, string $dash = '-'): string
    {
        $stripped = $this->removeDashes($isbn);
        $len = strlen($stripped);

        if ($len === 10) {
            return $this->format10($stripped, $dash);
        }

        if ($len === 13) {
            return $this->format13($stripped, $dash);
        }

        throw new \InvalidArgumentException('Invalid ISBN length for formatting');
    }

    private function checkIsbn10Sum(string $code): bool
    {
        $acc = 0;

        for ($i = 0; $i < 9; $i++) {
            $acc += (int) $code[$i] * (10 - $i);
        }

        $last = $code[9];
        $expected = $last === 'X' ? 10 : (int) $last;

        return ($acc + $expected) % 11 === 0;
    }

    private function checkIsbn13Sum(string $code): bool
    {
        $acc = 0;

        for ($i = 0; $i < 12; $i++) {
            $factor = ($i % 2 === 0) ? 1 : 3;
            $acc += (int) $code[$i] * $factor;
        }

        $computed = (10 - ($acc % 10)) % 10;

        return (int) $code[12] === $computed;
    }

    private function format10(string $isbn, string $sep): string
    {
        return $isbn[0] . $sep
            . substr($isbn, 1, 4) . $sep
            . substr($isbn, 5, 4) . $sep
            . $isbn[9];
    }

    private function format13(string $isbn, string $sep): string
    {
        return substr($isbn, 0, 3) . $sep
            . $isbn[3] . $sep
            . substr($isbn, 4, 6) . $sep
            . substr($isbn, 10, 3);
    }

    private function removeDashes(string $code): string
    {
        return str_replace(['-', ' '], '', $code);
    }
}
