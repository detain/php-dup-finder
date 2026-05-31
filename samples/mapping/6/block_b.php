<?php
declare(strict_types=1);

namespace App\Security\JWT\Mapper;

use App\Domain\Entity\User;
use App\Security\JWT\DTO\JwtPayloadDTO;
use App\Security\JWT\DTO\JwtClaimsDTO;

final readonly class JwtUserMapper
{
    public function toPayloadDTO(User $user): JwtPayloadDTO
    {
        $dto = new JwtPayloadDTO();
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
        $dto->iat = time();
        $dto->exp = time() + 3600;

        return $dto;
    }

    public function toClaimsDTO(User $user): JwtClaimsDTO
    {
        $dto = new JwtClaimsDTO();
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
        $dto->customClaims = $this->buildCustomClaims($user);

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

    private function buildCustomClaims(User $user): array
    {
        return [
            'org_id' => $user->getOrganizationId()?->toString(),
            'tier' => $user->getSubscriptionTier(),
        ];
    }
}
