<?php
declare(strict_types=1);

namespace App\Address;

final class ShippingAddress
{
    public function __construct(
        public readonly string $recipient,
        public readonly string $line1,
        public readonly ?string $line2,
        public readonly string $city,
        public readonly ?string $region,
        public readonly string $postalCode,
        public readonly string $countryIso2,
        public readonly ?string $phone,
    ) {
        $errors = [];
        if ($recipient === '') $errors[] = 'recipient required';
        if ($line1 === '') $errors[] = 'street required';
        if ($city === '') $errors[] = 'city required';
        if ($postalCode === '') $errors[] = 'postal code required';
        if (strlen($countryIso2) !== 2) $errors[] = 'country must be ISO-2';
        if ($errors) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }

    public static function fromInput(array $a): self
    {
        return new self(
            trim((string)($a['name'] ?? '')),
            trim((string)($a['street'] ?? $a['address1'] ?? '')),
            isset($a['street2']) ? trim((string)$a['street2']) : (isset($a['address2']) ? trim((string)$a['address2']) : null),
            trim((string)($a['city'] ?? '')),
            isset($a['region']) ? trim((string)$a['region']) : (isset($a['state']) ? trim((string)$a['state']) : null),
            strtoupper(trim((string)($a['zip'] ?? $a['postal_code'] ?? ''))),
            strtoupper(trim((string)($a['country'] ?? ''))),
            !empty($a['phone']) ? preg_replace('/[^0-9+]/', '', (string)$a['phone']) : null,
        );
    }

    public function countryIso3(): string
    {
        $map = ['US' => 'USA', 'CA' => 'CAN', 'GB' => 'GBR', 'DE' => 'DEU', 'FR' => 'FRA'];
        return $map[$this->countryIso2] ?? $this->countryIso2;
    }
}
