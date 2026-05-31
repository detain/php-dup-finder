<?php
declare(strict_types=1);

namespace Acme\Crm\Customer;

final class Customer
{
    public function __construct(
        public readonly int $id,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email');
        }
        if ($this->firstName === '' || $this->lastName === '') {
            throw new \InvalidArgumentException('Missing name fields');
        }
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int)$row['id'],
            trim((string)$row['first_name']),
            trim((string)$row['last_name']),
            strtolower(trim((string)$row['email'])),
            isset($row['phone']) ? trim((string)$row['phone']) : null,
            new \DateTimeImmutable((string)$row['created_at']),
            new \DateTimeImmutable((string)$row['updated_at']),
        );
    }

    public function fullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    public function memberSinceHuman(): string
    {
        return $this->createdAt->format('F j, Y');
    }
}
