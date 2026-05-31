<?php
declare(strict_types=1);

namespace App\Core\Security\Mapper;

use App\Domain\Entity\User;
use App\Core\DTO\DTOInterface;

interface SecurityMappingContext
{
    public function getSessionDuration(): \DateInterval;
    public function getExtraFields(): array;
    public function includeIpAddress(): bool;
}

abstract class BaseSecurityUserMapper
{
    public function map(User $user, DTOInterface $dto, ?SecurityMappingContext $context = null): DTOInterface
    {
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
        $dto->sessionExpiresAt = $this->calculateExpiry($context);
        $dto->isEmailVerified = $user->isEmailVerified();
        $dto->isTwoFactorEnabled = $user->isTwoFactorEnabled();

        if ($context !== null) {
            foreach ($context->getExtraFields() as $field => $value) {
                $dto->{$field} = $value;
            }
            if ($context->includeIpAddress()) {
                $dto->ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            }
        }

        return $dto;
    }

    protected function extractRoles(array $roles): array
    {
        return array_map(fn($role) => $role->getName(), $roles);
    }

    protected function extractPermissions(array $roles): array
    {
        $permissions = [];
        foreach ($roles as $role) {
            $permissions = array_merge($permissions, $role->getPermissions());
        }
        return array_unique($permissions);
    }

    protected function calculateExpiry(?SecurityMappingContext $context): string
    {
        $duration = $context?->getSessionDuration() ?? new \DateInterval('P1D');
        return (new \DateTimeImmutable())->add($duration)->format(\DateTimeInterface::ATOM);
    }
}

final class SessionUserMapper extends BaseSecurityUserMapper {}
final class JwtUserMapper extends BaseSecurityUserMapper {}
final class AuditUserMapper extends BaseSecurityUserMapper {}
