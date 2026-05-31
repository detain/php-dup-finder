<?php
declare(strict_types=1);

namespace App\Customer\Model;

use App\Customer\Entity\Customer;

final class CustomerModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $status,
        public readonly bool $acceptsTerms,
        public readonly bool $subscribeNewsletter,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $lastLoginAt = null,
        public readonly ?string $phone = null,
        public readonly ?string $referralCode = null
    ) {}

    public static function fromEntity(Customer $customer): self
    {
        return new self(
            id: $customer->getId(),
            email: $customer->getEmail(),
            firstName: $customer->getFirstName(),
            lastName: $customer->getLastName(),
            status: $customer->getStatus(),
            acceptsTerms: $customer->getStatus() !== null,
            subscribeNewsletter: true,
            createdAt: $customer->getCreatedAt(),
            lastLoginAt: $customer->getLastLoginAt(),
            phone: $customer->getPhone(),
            referralCode: $customer->getReferralCode()
        );
    }

    public function getFullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function toRegistrationArray(): array
    {
        return [
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone' => $this->phone
        ];
    }

    public function toProfileArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'full_name' => $this->getFullName(),
            'phone' => $this->phone,
            'member_since' => $this->createdAt->format('F j, Y'),
            'is_active' => $this->isActive()
        ];
    }
}
