<?php

declare(strict_types=1);

namespace App\Library;

final class PublicationIdentifierProcessor
{
    public function isIsbn10(string $identifier): bool
    {
        $scrubbed = $this->clean($identifier);

        return strlen($scrubbed) === 10 && $this->verifyIsbn10Check($scrubbed);
    }

    public function isIsbn13(string $identifier): bool
    {
        $scrubbed = $this->clean($identifier);

        return strlen($scrubbed) === 13 && $this->verifyIsbn13Check($scrubbed);
    }

    public function isIsbn(string $identifier): bool
    {
        $scrubbed = $this->clean($identifier);
        $len = strlen($scrubbed);

        if ($len === 10) {
            return $this->verifyIsbn10Check($scrubbed);
        }

        if ($len === 13) {
            return $this->verifyIsbn13Check($scrubbed);
        }

        return false;
    }

    public function calculateIsbn10Checksum(string $nineDigits): string
    {
        $clean = $this->clean($nineDigits);

        if (strlen($clean) !== 9) {
            throw new \InvalidArgumentException('Expected 9 digits for ISBN-10 checksum');
        }

        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $clean[$i] * (10 - $i);
        }

        $remainder = $sum % 11;
        $result = (11 - $remainder) % 11;

        return $result === 10 ? 'X' : (string) $result;
    }

    public function calculateIsbn13Checksum(string $twelveDigits): string
    {
        $clean = $this->clean($twelveDigits);

        if (strlen($clean) !== 12) {
            throw new \InvalidArgumentException('Expected 12 digits for ISBN-13 checksum');
        }

        $total = 0;

        for ($i = 0; $i < 12; $i++) {
            $weight = ($i % 2 === 0) ? 1 : 3;
            $total += (int) $clean[$i] * $weight;
        }

        $check = (10 - ($total % 10)) % 10;

        return (string) $check;
    }

    public function transformToIsbn13(string $isbn10): string
    {
        $scrubbed = $this->clean($isbn10);

        if (!$this->isIsbn10($scrubbed)) {
            throw new \InvalidArgumentException('Cannot transform invalid ISBN-10');
        }

        $withoutCheck = '978' . substr($scrubbed, 0, 9);

        return $withoutCheck . $this->calculateIsbn13Checksum($withoutCheck);
    }

    public function transformToIsbn10(string $isbn13): string
    {
        $scrubbed = $this->clean($isbn13);

        if (!$this->isIsbn13($scrubbed)) {
            throw new \InvalidArgumentException('Cannot transform invalid ISBN-13');
        }

        if (substr($scrubbed, 0, 3) !== '978') {
            throw new \InvalidArgumentException('ISBN-13 must have 978 prefix for conversion');
        }

        $withoutCheck = substr($scrubbed, 3, 9);

        return $withoutCheck . $this->calculateIsbn10Checksum($withoutCheck);
    }

    public function prettyPrint(string $isbn, string $delimiter = '-'): string
    {
        $scrubbed = $this->clean($isbn);
        $len = strlen($scrubbed);

        if ($len === 10) {
            return $this->prettify10($scrubbed, $delimiter);
        }

        if ($len === 13) {
            return $this->prettify13($scrubbed, $delimiter);
        }

        throw new \InvalidArgumentException('Cannot format ISBN with length: ' . $len);
    }

    private function verifyIsbn10Check(string $isbn): bool
    {
        $sum = 0;

        for ($pos = 0; $pos < 9; $pos++) {
            $sum += (int) $isbn[$pos] * (10 - $pos);
        }

        $tail = $isbn[9];
        $expected = $tail === 'X' ? 10 : (int) $tail;

        return ($sum + $expected) % 11 === 0;
    }

    private function verifyIsbn13Check(string $isbn): bool
    {
        $sum = 0;

        for ($pos = 0; $pos < 12; $pos++) {
            $multiplier = ($pos % 2 === 0) ? 1 : 3;
            $sum += (int) $isbn[$pos] * $multiplier;
        }

        $computed = (10 - ($sum % 10)) % 10;

        return (int) $isbn[12] === $computed;
    }

    private function prettify10(string $isbn, string $sep): string
    {
        return $isbn[0] . $sep
            . substr($isbn, 1, 4) . $sep
            . substr($isbn, 5, 4) . $sep
            . $isbn[9];
    }

    private function prettify13(string $isbn, string $sep): string
    {
        return substr($isbn, 0, 3) . $sep
            . $isbn[3] . $sep
            . substr($isbn, 4, 6) . $sep
            . substr($isbn, 10, 3);
    }

    private function clean(string $identifier): string
    {
        return str_replace(['-', ' '], '', $identifier);
    }
}
