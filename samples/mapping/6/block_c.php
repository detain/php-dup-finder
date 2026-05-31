<?php
declare(strict_types=1);

namespace App\Security\Audit\Mapper;

use App\Domain\Entity\User;
use App\Security\Audit\DTO\AuditUserDTO;
use App\Security\Audit\DTO\AuditActorDTO;

final readonly class AuditUserMapper
{
    public function toAuditDTO(User $user, string $action): AuditUserDTO
    {
        $dto = new AuditUserDTO();
        $dto->id = $user->getId()->toString();
        $dto->email = $user->getEmail();
        $dto->firstName = $user->getProfile()->getFirstName();
        $dto->lastName = $user->getProfile()->getLastName();
        $dto->displayName = $user->getProfile()->getDisplayName();
        $dto->avatarUrl = $user->getProfile()->getAvatarUrl();
        $dto->status = $user->getStatus()->value;
        $dto->roles = $this->extractRoles($user->getRoles());
        $dto->permissions = $this->extractPermissions($user->getRoles());
        $dto->createdAt = $user->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->lastLoginAt = $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM);
        $dto->sessionStartedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $dto->sessionExpiresAt = $this->calculateExpiry();
        $dto->isEmailVerified = $user->isEmailVerified();
        $dto->isTwoFactorEnabled = $user->isTwoFactorEnabled();
        $dto->action = $action;
        $dto->timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $dto->ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        return $dto;
    }

    public function toActorDTO(User $user): AuditActorDTO
    {
        $dto = new AuditActorDTO();
        $dto->id = $user->getId()->toString();
        $dto->email = $user->getEmail();
        $dto->firstName = $user->getProfile()->getFirstName();
        $dto->lastName = $user->getProfile()->getLastName();
        $dto->displayName = $user->getProfile()->getDisplayName();
        $dto->avatarUrl = $user->getProfile()->getAvatarUrl();
        $dto->status = $user->getStatus()->value;
        $dto->roles = $this->extractRoles($user->getRoles());
        $dto->permissions = $this->extractPermissions($user->getRoles());
        $dto->createdAt = $user->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->lastLoginAt = $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM);
        $dto->sessionStartedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $dto->sessionExpiresAt = $this->calculateExpiry();
        $dto->isEmailVerified = $user->isEmailVerified();
        $dto->isTwoFactorEnabled = $user->isTwoFactorEnabled();

        return $dto;
    }

    private function extractRoles(array $roles): array
    {
        return array_map(fn($role) => $role->getName(), $roles);
    }

    private function extractPermissions(array $roles): array
    {
        $permissions = [];
        foreach ($roles as $role) {
            $permissions = array_merge($permissions, $role->getPermissions());
        }
        return array_unique($permissions);
    }

    private function calculateExpiry(): string
    {
        return (new \DateTimeImmutable('+24 hours'))->format(\DateTimeInterface::ATOM);
    }
}
