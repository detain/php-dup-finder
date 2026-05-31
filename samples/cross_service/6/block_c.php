<?php
declare(strict_types=1);

namespace Acme\CrmService\Address;

final class CrmAddressCleanser
{
    public function cleanse(array $contactAddress): array
    {
        $line = strtolower(trim((string) $contactAddress['street']));
        $expansions = ['street' => 'st', 'avenue' => 'ave', 'boulevard' => 'blvd', 'road' => 'rd'];
        foreach ($expansions as $long => $short) {
            $line = preg_replace('/\b' . preg_quote($long, '/') . '\b/', $short, $line) ?? $line;
        }
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;

        $cityClean = ucwords(strtolower(trim((string) $contactAddress['city'])));
        $st = strtoupper(trim((string) ($contactAddress['region'] ?? '')));

        $postal = (string) ($contactAddress['postal_code'] ?? '');
        $postalDigits = preg_replace('/[^0-9]/', '', $postal) ?? '';
        if (strlen($postalDigits) === 9) {
            $postalDigits = substr($postalDigits, 0, 5) . '-' . substr($postalDigits, 5);
        }

        $country = strtoupper(trim((string) ($contactAddress['country'] ?? 'US')));
        if ($country === 'USA' || $country === 'UNITED STATES') {
            $country = 'US';
        }

        return [
            'street'  => $line,
            'city'    => $cityClean,
            'region'  => $st,
            'postal'  => $postalDigits,
            'country' => $country,
        ];
    }
}
