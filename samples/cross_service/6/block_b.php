<?php
declare(strict_types=1);

namespace Acme\ShippingService\Address;

final class ShippingLabelAddressFormatter
{
    /** @var array<string,string> */
    private array $suffixMap = [
        'street'    => 'st',
        'avenue'    => 'ave',
        'boulevard' => 'blvd',
        'road'      => 'rd',
    ];

    public function format(array $raw): array
    {
        $street = strtolower(trim((string) $raw['line1']));
        foreach ($this->suffixMap as $long => $short) {
            $street = preg_replace('/\b' . preg_quote($long, '/') . '\b/', $short, $street) ?? $street;
        }
        $street = preg_replace('/\s+/', ' ', $street) ?? $street;

        $cityNorm = ucwords(strtolower(trim((string) $raw['city'])));
        $stateNorm = strtoupper(trim((string) ($raw['state'] ?? '')));

        $postal = preg_replace('/[^0-9]/', '', (string) ($raw['zip'] ?? '')) ?? '';
        if (strlen($postal) === 9) {
            $postal = substr($postal, 0, 5) . '-' . substr($postal, 5);
        }

        $countryCode = strtoupper(trim((string) ($raw['country'] ?? 'US')));
        if ($countryCode === 'USA' || $countryCode === 'UNITED STATES') {
            $countryCode = 'US';
        }

        return [
            'street'  => $street,
            'city'    => $cityNorm,
            'state'   => $stateNorm,
            'postal'  => $postal,
            'country' => $countryCode,
        ];
    }
}
