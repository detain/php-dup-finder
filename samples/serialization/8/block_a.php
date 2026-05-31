<?php

declare(strict_types=1);

namespace App\Repository\Mapper;

class UserResultMapper
{
    public function map(array $row): ?User
    {
        if ($row === null || count($row) === 0) {
            return null;
        }

        return new User(
            (string)$row['id'],
            (string)$row['email'],
            (string)$row['name'],
            isset($row['avatar_url']) && $row['avatar_url'] !== null ? (string)$row['avatar_url'] : null,
            isset($row['created_at']) ? new \DateTimeImmutable($row['created_at']) : new \DateTimeImmutable(),
            isset($row['updated_at']) && $row['updated_at'] !== null ? new \DateTimeImmutable($row['updated_at']) : null,
            isset($row['is_active']) ? (bool)$row['is_active'] : true,
            isset($row['roles']) ? $this->unserializeRoles($row['roles']) : []
        );
    }

    public function mapMany(array $rows): array
    {
        return array_map(fn(array $row) => $this->map($row), $rows);
    }

    public function mapToArray(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'avatar_url' => $user->getAvatarUrl(),
            'is_active' => $user->isActive() ? 1 : 0,
            'roles' => $this->serializeRoles($user->getRoles()),
            'created_at' => $user->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $user->getUpdatedAt()?->format('Y-m-d H:i:s')
        ];
    }

    private function unserializeRoles(string $rolesJson): array
    {
        if (empty($rolesJson)) {
            return [];
        }

        $decoded = json_decode($rolesJson, true);

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function serializeRoles(array $roles): string
    {
        return json_encode($roles);
    }
}
