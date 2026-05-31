<?php
declare(strict_types=1);

namespace Acme\Tax\Validation;

interface VatVerificationDriver
{
    public function verify(string $country, string $number): bool;
}

final class VatValidator
{
    private const PATTERNS = [
        'AT' => '/^U\d{8}$/',     'BE' => '/^0\d{9}$/',
        'DE' => '/^\d{9}$/',       'NL' => '/^\d{9}B\d{2}$/',
        'IT' => '/^\d{11}$/',      'ES' => '/^[A-Z0-9]\d{7}[A-Z0-9]$/',
        'PT' => '/^\d{9}$/',       'FR' => '/^[A-HJ-NP-Z0-9]{2}\d{9}$/',
        'GB' => '/^(\d{9}|\d{12})$/',
    ];

    public function __construct(private readonly ?VatVerificationDriver $driver = null) {}

    public function isValid(string $vat): bool
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $vat) ?? '');
        if (strlen($normalized) < 4) {
            return false;
        }
        $country = substr($normalized, 0, 2);
        $body    = substr($normalized, 2);
        $pattern = self::PATTERNS[$country] ?? null;
        if ($pattern === null || preg_match($pattern, $body) !== 1) {
            return false;
        }
        return $this->driver === null || $this->driver->verify($country, $body);
    }
}
