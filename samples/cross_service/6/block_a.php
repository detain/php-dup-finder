<?php
declare(strict_types=1);

namespace Acme\CheckoutService\Address;

final class CheckoutAddressNormalizer
{
    private const SUFFIXES = ['street' => 'st', 'avenue' => 'ave', 'boulevard' => 'blvd', 'road' => 'rd'];

    public function normalize(array $address): array
    {
        $line1 = strtolower(trim((string) $address['line1']));
        foreach (self::SUFFIXES as $long => $short) {
            $line1 = preg_replace('/\b' . preg_quote($long, '/') . '\b/', $short, $line1) ?? $line1;
        }
        $line1 = preg_replace('/\s+/', ' ', $line1) ?? $line1;

        $city = ucwords(strtolower(trim((string) $address['city'])));

        $state = strtoupper(trim((string) ($address['state'] ?? '')));

        $zip = trim((string) ($address['zip'] ?? ''));
        $zip = preg_replace('/[^0-9]/', '', $zip) ?? '';
        if (strlen($zip) === 9) {
            $zip = substr($zip, 0, 5) . '-' . substr($zip, 5);
        }

        $country = strtoupper(trim((string) ($address['country'] ?? 'US')));
        if ($country === 'USA' || $country === 'UNITED STATES') {
            $country = 'US';
        }

        return [
            'line1'   => $line1,
            'city'    => $city,
            'state'   => $state,
            'zip'     => $zip,
            'country' => $country,
        ];
    }
}
