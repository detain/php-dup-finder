<?php
declare(strict_types=1);

namespace Acme\Tax\Validation;

final class RegexVatValidator
{
    private const PATTERNS = [
        'AT' => '/^ATU\d{8}$/',
        'BE' => '/^BE0\d{9}$/',
        'BG' => '/^BG\d{9,10}$/',
        'CY' => '/^CY\d{8}[A-Z]$/',
        'CZ' => '/^CZ\d{8,10}$/',
        'DE' => '/^DE\d{9}$/',
        'DK' => '/^DK\d{8}$/',
        'EE' => '/^EE\d{9}$/',
        'ES' => '/^ES[A-Z0-9]\d{7}[A-Z0-9]$/',
        'FI' => '/^FI\d{8}$/',
        'FR' => '/^FR[A-HJ-NP-Z0-9]{2}\d{9}$/',
        'GB' => '/^GB(\d{9}|\d{12}|GD\d{3}|HA\d{3})$/',
        'HR' => '/^HR\d{11}$/',
        'HU' => '/^HU\d{8}$/',
        'IE' => '/^IE\d{7}[A-W]([A-I]|W)?$/',
        'IT' => '/^IT\d{11}$/',
        'LT' => '/^LT(\d{9}|\d{12})$/',
        'LU' => '/^LU\d{8}$/',
        'LV' => '/^LV\d{11}$/',
        'MT' => '/^MT\d{8}$/',
        'NL' => '/^NL\d{9}B\d{2}$/',
        'PL' => '/^PL\d{10}$/',
        'PT' => '/^PT\d{9}$/',
        'RO' => '/^RO\d{2,10}$/',
        'SE' => '/^SE\d{12}$/',
        'SI' => '/^SI\d{8}$/',
        'SK' => '/^SK\d{10}$/',
    ];

    public function isValid(string $vat): bool
    {
        $vat = strtoupper(preg_replace('/\s+/', '', $vat) ?? '');
        if (strlen($vat) < 4) {
            return false;
        }
        $country = substr($vat, 0, 2);
        $pattern = self::PATTERNS[$country] ?? null;
        if ($pattern === null) {
            return false;
        }
        return preg_match($pattern, $vat) === 1;
    }
}
