<?php
declare(strict_types=1);

namespace App\Address\Model;

use App\Address\Entity\Address;

final class AddressModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $addressLine1,
        public readonly ?string $addressLine2,
        public readonly string $city,
        public readonly string $state,
        public readonly string $postalCode,
        public readonly string $countryCode,
        public readonly ?string $company = null,
        public readonly ?string $phone = null,
        public readonly bool $isDefault = false,
        public readonly string $type = 'shipping'
    ) {}

    public static function fromEntity(Address $address): self
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
            type: $address->getType()
        );
    }

    public function getFormattedSingleLine(): string
    {
        $parts = array_filter([
            $this->addressLine1,
            $this->addressLine2,
            "{$this->city}, {$this->state} {$this->postalCode}"
        ]);

        return implode(', ', $parts);
    }

    public function getFormattedMultiLine(): string
    {
        $parts = [
            $this->addressLine1
        ];

        if ($this->addressLine2 !== null) {
            $parts[] = $this->addressLine2;
        }

        $parts[] = "{$this->city}, {$this->state} {$this->postalCode}";

        return implode("\n", $parts);
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
            'type' => $this->type
        ];
    }
}
