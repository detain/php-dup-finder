<?php

declare(strict_types=1);

namespace App\Dto;

class UserArrayConverter
{
    public function fromEntity(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'avatar_url' => $user->getAvatarUrl(),
            'is_active' => $user->isActive(),
            'created_at' => $this->formatDateTime($user->getCreatedAt()),
            'updated_at' => $this->formatNullableDateTime($user->getUpdatedAt()),
            'roles' => $user->getRoles()
        ];
    }

    public function toEntity(array $data): User
    {
        return new User(
            $data['id'],
            $data['email'],
            $data['name'],
            $data['avatar_url'] ?? null,
            $this->parseDateTime($data['created_at']),
            isset($data['updated_at']) ? $this->parseDateTime($data['updated_at']) : null,
            $data['is_active'] ?? true,
            $data['roles'] ?? []
        );
    }

    public function fromEntityCompact(User $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'avatar_url' => $user->getAvatarUrl(),
            'is_active' => $user->isActive()
        ];
    }

    public function fromEntitySummary(User $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'is_active' => $user->isActive()
        ];
    }

    public function fromEntities(array $users): array
    {
        return array_map(fn(User $user) => $this->fromEntity($user), $users);
    }

    private function formatDateTime(\DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }

    private function formatNullableDateTime(?\DateTimeInterface $dateTime): ?string
    {
        return $dateTime?->format('Y-m-d H:i:s');
    }

    private function parseDateTime(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }
}
