<?php
declare(strict_types=1);

namespace Acme\Crm\Api\Dto;

final class CustomerDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public static function fromRequest(array $payload): self
    {
        if (!filter_var($payload['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email');
        }
        if (empty($payload['first_name']) || empty($payload['last_name'])) {
            throw new \InvalidArgumentException('Missing name fields');
        }
        return new self(
            id: (int)($payload['id'] ?? 0),
            firstName: trim((string)$payload['first_name']),
            lastName: trim((string)$payload['last_name']),
            email: strtolower(trim((string)$payload['email'])),
            phone: isset($payload['phone']) ? trim((string)$payload['phone']) : null,
            createdAt: (string)($payload['created_at'] ?? gmdate('c')),
            updatedAt: (string)($payload['updated_at'] ?? gmdate('c')),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}

final class CustomerApiController
{
    public function show(int $id, CustomerLookup $lookup): array
    {
        $row = $lookup->findById($id);
        $dto = CustomerDto::fromRequest($row);
        return $dto->toArray();
    }
}
