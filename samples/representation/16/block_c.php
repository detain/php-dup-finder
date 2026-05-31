<?php
declare(strict_types=1);

namespace App\Checkout\ViewModel;

final class ShippingAddressViewModel
{
    public string $id;
    public string $fullName;
    public string $addressLine1;
    public string $addressLine2;
    public string $cityStatePostal;
    public string $country;
    public string $phone;
    public bool $isDefault;
    public string $editUrl;
    public string $deleteUrl;

    public static function fromAddress(
        \App\Address\Entity\Address $address,
        string $fullName
    ): self {
        $vm = new self();
        $vm->id = $address->getId();
        $vm->fullName = $fullName;
        $vm->addressLine1 = $address->getAddressLine1();
        $vm->addressLine2 = $address->getAddressLine2() ?? '';
        $vm->cityStatePostal = self::formatCityStatePostal(
            $address->getCity(),
            $address->getState(),
            $address->getPostalCode()
        );
        $vm->country = self::getCountryName($address->getCountryCode());
        $vm->phone = $address->getPhone() ?? '';
        $vm->isDefault = $address->isDefault();
        $vm->editUrl = "/account/addresses/{$address->getId()}/edit";
        $vm->deleteUrl = "/account/addresses/{$address->getId()}/delete";

        return $vm;
    }

    private static function formatCityStatePostal(
        string $city,
        string $state,
        string $postalCode
    ): string {
        return "{$city}, {$state} {$postalCode}";
    }

    private static function getCountryName(string $countryCode): string
    {
        $countries = [
            'US' => 'United States',
            'CA' => 'Canada',
            'GB' => 'United Kingdom',
            'DE' => 'Germany',
            'FR' => 'France',
            'AU' => 'Australia'
        ];

        return $countries[$countryCode] ?? $countryCode;
    }

    public function getFormattedSingleLine(): string
    {
        $parts = [$this->fullName, $this->addressLine1];

        if ($this->addressLine2 !== '') {
            $parts[] = $this->addressLine2;
        }

        $parts[] = $this->cityStatePostal;
        $parts[] = $this->country;

        return implode(', ', array_filter($parts));
    }

    public function hasPhone(): bool
    {
        return $this->phone !== '';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->fullName,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city_state_postal' => $this->cityStatePostal,
            'country' => $this->country,
            'phone' => $this->phone,
            'is_default' => $this->isDefault
        ];
    }
}
