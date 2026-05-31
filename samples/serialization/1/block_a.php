<?php

declare(strict_types=1);

namespace App\Entity;

class User
{
    private string $id;
    private string $email;
    private string $name;
    private ?string $avatarUrl;
    private DateTimeImmutable $createdAt;
    private ?DateTimeImmutable $updatedAt;
    private bool $isActive;
    private array $roles;

    public function __construct(
        string $id,
        string $email,
        string $name,
        ?string $avatarUrl,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
        bool $isActive,
        array $roles
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->name = $name;
        $this->avatarUrl = $avatarUrl;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->isActive = $isActive;
        $this->roles = $roles;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl,
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt?->format('c'),
            'is_active' => $this->isActive,
            'roles' => $this->roles,
            'meta' => [
                'type' => 'user',
                'serialized_at' => (new DateTimeImmutable())->format('c')
            ]
        ];
    }

    public function toCompactArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl,
            'is_active' => $this->isActive
        ];
    }

    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl,
            'is_active' => $this->isActive
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }
}
