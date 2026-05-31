<?php
declare(strict_types=1);

namespace App\Address\DTO;

final class AddressDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $addressLine1,
        public readonly ?string $addressLine2,
        public readonly string $city,
        public readonly string $state,
        public readonly string $postalCode,
        public readonly string $countryCode,
        public readonly ?string $company,
        public readonly ?string $phone,
        public readonly bool $isDefault,
        public readonly string $addressType
    ) {}

    public static function fromEntity(\App\Address\Entity\Address $address): self
    {
        return new self(
            id: $address->getId(),
            addressLine1: $address->getAddressLine1(),
            addressLine2: $address->getAddressLine2(),
            city: $address->getCity(),
            state: $address->getState(),
            postalCode: $address->getPostalCode(),
            countryCode: $address->getCountryCode(),
            company: $address->getCompany(),
            phone: $address->getPhone(),
            isDefault: $address->isDefault(),
            addressType: $address->getType()
        );
    }

    public static function fromRequest(array $data): self
    {
        return new self(
            id: $data['id'] ?? uniqid('addr_'),
            addressLine1: $data['address_line_1'] ?? '',
            addressLine2: $data['address_line_2'] ?? null,
            city: $data['city'] ?? '',
            state: $data['state'] ?? '',
            postalCode: $data['postal_code'] ?? '',
            countryCode: $data['country_code'] ?? 'US',
            company: $data['company'] ?? null,
            phone: $data['phone'] ?? null,
            isDefault: $data['is_default'] ?? false,
            addressType: $data['address_type'] ?? 'shipping'
        );
    }

    public function getFormattedAddress(): string
    {
        $parts = [
            $this->addressLine1
        ];

        if ($this->addressLine2 !== null && $this->addressLine2 !== '') {
            $parts[] = $this->addressLine2;
        }

        $parts[] = "{$this->city}, {$this->state} {$this->postalCode}";
        $parts[] = $this->getCountryName();

        return implode("\n", $parts);
    }

    public function getCountryName(): string
    {
        $countries = [
            'US' => 'United States',
            'CA' => 'Canada',
            'GB' => 'United Kingdom',
            'DE' => 'Germany',
            'FR' => 'France',
            'AU' => 'Australia'
        ];

        return $countries[$this->countryCode] ?? $this->countryCode;
    }

    public function getSingleLineAddress(): string
    {
        $parts = [$this->addressLine1];

        if ($this->addressLine2 !== null && $this->addressLine2 !== '') {
            $parts[] = $this->addressLine2;
        }

        $parts[] = "{$this->city}, {$this->state} {$this->postalCode}";

        return implode(', ', $parts);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
            'company' => $this->company,
            'phone' => $this->phone,
            'is_default' => $this->isDefault,
            'address_type' => $this->addressType
        ];
    }

    public function withDefault(bool $isDefault): self
    {
        return new self(
            id: $this->id,
            addressLine1: $this->addressLine1,
            addressLine2: $this->addressLine2,
            city: $this->city,
            state: $this->state,
            postalCode: $this->postalCode,
            countryCode: $this->countryCode,
            company: $this->company,
            phone: $this->phone,
            isDefault: $isDefault,
            addressType: $this->addressType
        );
    }
}
