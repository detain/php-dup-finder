<?php
declare(strict_types=1);

namespace App\Customer\DTO;

final class CustomerRegistrationDTO
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $phone,
        public readonly ?string $referralCode,
        public readonly bool $acceptsTerms,
        public readonly bool $subscribeNewsletter,
        public readonly ?string $source = null
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            email: $data['email'] ?? '',
            password: $data['password'] ?? '',
            firstName: $data['first_name'] ?? '',
            lastName: $data['last_name'] ?? '',
            phone: $data['phone'] ?? null,
            referralCode: $data['referral_code'] ?? null,
            acceptsTerms: $data['accepts_terms'] ?? false,
            subscribeNewsletter: $data['subscribe_newsletter'] ?? false,
            source: $data['source'] ?? null
        );
    }

    public function getFullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    public function toEntityData(): array
    {
        return [
            'email' => strtolower(trim($this->email)),
            'first_name' => trim($this->firstName),
            'last_name' => trim($this->lastName),
            'phone' => $this->phone,
            'referral_code' => $this->referralCode,
            'accepts_terms' => $this->acceptsTerms,
            'subscribe_newsletter' => $this->subscribeNewsletter,
            'source' => $this->source
        ];
    }

    public function getPasswordHash(): string
    {
        return password_hash($this->password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }

    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone' => $this->phone,
            'referral_code' => $this->referralCode,
            'accepts_terms' => $this->acceptsTerms,
            'subscribe_newsletter' => $this->subscribeNewsletter,
            'source' => $this->source
        ];
    }
}
