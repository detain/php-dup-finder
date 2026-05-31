<?php
declare(strict_types=1);

namespace App\Address\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'addresses')]
#[ORM\Index(columns: ['customer_id', 'type'], name: 'idx_addresses_customer_type')]
#[ORM\Index(columns: ['country_code'], name: 'idx_addresses_country')]
class Address
{
    public const TYPE_SHIPPING = 'shipping';
    public const TYPE_BILLING = 'billing';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $customerId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $addressLine1;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $addressLine2 = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $city;

    #[ORM\Column(type: 'string', length: 100)]
    private string $state;

    #[ORM\Column(type: 'string', length: 20)]
    private string $postalCode;

    #[ORM\Column(type: 'string', length: 2)]
    private string $countryCode;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $company = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $type;

    #[ORM\Column(type: 'boolean')]
    private bool $isDefault = false;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $validationId = null;

    public function __construct(
        string $id,
        string $customerId,
        string $addressLine1,
        string $city,
        string $state,
        string $postalCode,
        string $countryCode,
        string $type = self::TYPE_SHIPPING
    ) {
        $this->id = $id;
        $this->customerId = $customerId;
        $this->addressLine1 = $addressLine1;
        $this->city = $city;
        $this->state = $state;
        $this->postalCode = $postalCode;
        $this->countryCode = $countryCode;
        $this->type = $type;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function getAddressLine1(): string
    {
        return $this->addressLine1;
    }

    public function getAddressLine2(): ?string
    {
        return $this->addressLine2;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setAddressLine2(?string $addressLine2): void
    {
        $this->addressLine2 = $addressLine2;
    }

    public function setCompany(?string $company): void
    {
        $this->company = $company;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function setDefault(bool $isDefault): void
    {
        $this->isDefault = $isDefault;
    }

    public function getFormattedAddress(): string
    {
        $parts = [$this->addressLine1];

        if ($this->addressLine2 !== null) {
            $parts[] = $this->addressLine2;
        }

        $parts[] = "{$this->city}, {$this->state} {$this->postalCode}";

        return implode("\n", $parts);
    }
}
