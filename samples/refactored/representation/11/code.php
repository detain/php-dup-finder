<?php
declare(strict_types=1);

namespace App\User\Model;

interface UserRepresentationInterface
{
    public function getId(): string;
    public function getEmail(): string;
    public function getFirstName(): string;
    public function getLastName(): string;
    public function getRole(): string;
    public function isActive(): bool;
    public function getCreatedAt(): \DateTimeImmutable;
}

final class UserModelMapper
{
    public function toDTO(\App\User\Entity\User $user): UserDTO
    {
        return new UserDTO(
            id: $user->getId(),
            email: $user->getEmail(),
            firstName: $user->getFirstName(),
            lastName: $user->getLastName(),
            role: $user->getRole(),
            avatarUrl: $user->getAvatarUrl(),
            createdAt: $user->getCreatedAt(),
            isActive: $user->isActive()
        );
    }

    public function toApiModel(\App\User\Entity\User $user): UserApiModel
    {
        $model = new UserApiModel();
        $model->email = $user->getEmail();
        $model->firstName = $user->getFirstName();
        $model->lastName = $user->getLastName();
        $model->role = $user->getRole();
        $model->avatarUrl = $user->getAvatarUrl();
        $model->isActive = $user->isActive();
        $model->createdAt = $user->getCreatedAt()->format('c');
        $model->updatedAt = $user->getUpdatedAt()->format('c');

        return $model;
    }

    public function toViewModel(
        \App\User\Entity\User $user,
        ?\App\User\Entity\CurrentUser $currentUser = null
    ): UserViewModel {
        $vm = new UserViewModel();
        $vm->displayName = trim("{$user->getFirstName()} {$user->getLastName()}");
        $vm->initials = mb_strtoupper(mb_substr($user->getFirstName(), 0, 1)) .
            mb_strtoupper(mb_substr($user->getLastName(), 0, 1));
        $vm->roleLabel = ucfirst($user->getRole());
        $vm->canEdit = $currentUser?->canEditUser($user) ?? false;
        $vm->canDelete = $currentUser?->canDeleteUser($user) ?? false;

        return $vm;
    }
}
