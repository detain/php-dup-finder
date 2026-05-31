<?php
declare(strict_types=1);

namespace App\User\DTO;

final class UserDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $role,
        public readonly ?string $avatarUrl,
        public readonly \DateTimeImmutable $createdAt,
        public readonly bool $isActive
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            email: $data['email'],
            firstName: $data['first_name'],
            lastName: $data['last_name'],
            role: $data['role'],
            avatarUrl: $data['avatar_url'] ?? null,
            createdAt: new \DateTimeImmutable($data['created_at']),
            isActive: (bool) $data['is_active']
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'role' => $this->role,
            'avatar_url' => $this->avatarUrl,
            'created_at' => $this->createdAt->format('c'),
            'is_active' => $this->isActive
        ];
    }

    public function getFullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function withRole(string $newRole): self
    {
        return new self(
            id: $this->id,
            email: $this->email,
            firstName: $this->firstName,
            lastName: $this->lastName,
            role: $newRole,
            avatarUrl: $this->avatarUrl,
            createdAt: $this->createdAt,
            isActive: $this->isActive
        );
    }
}
