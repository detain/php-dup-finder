<?php
declare(strict_types=1);

namespace Acme\Common\Address;

/**
 * acme/address-normalize is the canonical normalizer. Checkout, Shipping, and CRM
 * each translate their internal field names into a NormalizedAddress and use the
 * same DTO downstream — guaranteeing identical stored forms for dedupe.
 */
final class AddressNormalizer
{
    private const SUFFIX_MAP = [
        'street'    => 'st',
        'avenue'    => 'ave',
        'boulevard' => 'blvd',
        'road'      => 'rd',
    ];

    private const COUNTRY_ALIASES = [
        'USA'           => 'US',
        'UNITED STATES' => 'US',
    ];

    public function normalize(RawAddress $raw): NormalizedAddress
    {
        return new NormalizedAddress(
            line:    $this->normalizeStreet($raw->street),
            city:    ucwords(strtolower(trim($raw->city))),
            region:  strtoupper(trim($raw->region)),
            postal:  $this->normalizePostal($raw->postal),
            country: $this->normalizeCountry($raw->country),
        );
    }

    private function normalizeStreet(string $street): string
    {
        $value = strtolower(trim($street));
        foreach (self::SUFFIX_MAP as $long => $short) {
            $value = preg_replace('/\b' . preg_quote($long, '/') . '\b/', $short, $value) ?? $value;
        }
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    private function normalizePostal(string $postal): string
    {
        $digits = preg_replace('/[^0-9]/', '', $postal) ?? '';
        return strlen($digits) === 9 ? substr($digits, 0, 5) . '-' . substr($digits, 5) : $digits;
    }

    private function normalizeCountry(string $country): string
    {
        $up = strtoupper(trim($country));
        return self::COUNTRY_ALIASES[$up] ?? $up;
    }
}
