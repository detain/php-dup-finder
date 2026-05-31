<?php
declare(strict_types=1);

namespace Shipping\Carrier;

final class CarrierShipPayload
{
    /** @var array<string, mixed> */
    public array $payload;

    public function __construct(array $address)
    {
        if (empty($address['name']) || empty($address['street']) || empty($address['city'])) {
            throw new \InvalidArgumentException('Missing required carrier fields');
        }
        if (strlen((string)$address['country']) !== 2) {
            throw new \InvalidArgumentException('Carrier requires ISO-2 country');
        }
        $iso3Map = ['US' => 'USA', 'CA' => 'CAN', 'GB' => 'GBR', 'DE' => 'DEU', 'FR' => 'FRA'];
        $iso3 = $iso3Map[strtoupper((string)$address['country'])] ?? strtoupper((string)$address['country']);

        $this->payload = [
            'recipient' => [
                'contact_name' => trim((string)$address['name']),
                'address_line1' => trim((string)$address['street']),
                'address_line2' => trim((string)($address['street2'] ?? '')),
                'locality' => trim((string)$address['city']),
                'region_code' => trim((string)($address['region'] ?? '')),
                'postal_code' => strtoupper(trim((string)$address['zip'])),
                'country_iso3' => $iso3,
                'phone_number' => !empty($address['phone'])
                    ? preg_replace('/[^0-9+]/', '', (string)$address['phone'])
                    : null,
            ],
        ];
    }

    public function asJson(): string
    {
        return json_encode($this->payload, JSON_THROW_ON_ERROR);
    }
}

final class CarrierApi
{
    public function createLabel(CarrierShipPayload $payload): string
    {
        return 'LABEL-' . md5($payload->asJson());
    }
}
