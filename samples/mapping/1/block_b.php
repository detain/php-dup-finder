<?php
declare(strict_types=1);

namespace App\Application\API\Mapper;

use App\Domain\Entity\User;
use App\Application\DTO\UserDTO;
use App\Application\DTO\UserApiResponse;
use App\Application\DTO\UserViewModel;

final readonly class UserDTOAssembler
{
    public function __construct(
        private UserRoleMapper $roleMapper,
        private AddressMapper $addressMapper,
    ) {}

    public function toDTO(User $user): UserDTO
    {
        $dto = new UserDTO();
        $dto->id = $user->getId()->toString();
        $dto->email = $user->getEmail();
        $dto->firstName = $user->getProfile()->getFirstName();
        $dto->lastName = $user->getProfile()->getLastName();
        $dto->displayName = $user->getProfile()->getDisplayName();
        $dto->avatarUrl = $user->getProfile()->getAvatarUrl();
        $dto->phone = $user->getProfile()->getPhone();
        $dto->status = $user->getStatus()->value;
        $dto->roles = $this->roleMapper->toDtoList($user->getRoles());
        $dto->createdAt = $user->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->updatedAt = $user->getUpdatedAt()->format(\DateTimeInterface::ATOM);
        $dto->lastLoginAt = $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM);
        $dto->emailVerified = $user->isEmailVerified();
        $dto->twoFactorEnabled = $user->isTwoFactorEnabled();

        return $dto;
    }

    public function toApiResponse(User $user): UserApiResponse
    {
        $response = new UserApiResponse();
        $response->id = $user->getId()->toString();
        $response->email = $user->getEmail();
        $response->firstName = $user->getProfile()->getFirstName();
        $response->lastName = $user->getProfile()->getLastName();
        $response->displayName = $user->getProfile()->getDisplayName();
        $response->avatarUrl = $user->getProfile()->getAvatarUrl();
        $response->phone = $user->getProfile()->getPhone();
        $response->status = $user->getStatus()->value;
        $response->roles = $this->roleMapper->toDtoList($user->getRoles());
        $response->createdAt = $user->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $response->updatedAt = $user->getUpdatedAt()->format(\DateTimeInterface::ATOM);
        $response->lastLoginAt = $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM);
        $response->emailVerified = $user->isEmailVerified();
        $response->twoFactorEnabled = $user->isTwoFactorEnabled();

        return $response;
    }

    public function toViewModel(User $user): UserViewModel
    {
        $viewModel = new UserViewModel();
        $viewModel->id = $user->getId()->toString();
        $viewModel->email = $user->getEmail();
        $viewModel->firstName = $user->getProfile()->getFirstName();
        $viewModel->lastName = $user->getProfile()->getLastName();
        $viewModel->displayName = $user->getProfile()->getDisplayName();
        $viewModel->avatarUrl = $user->getProfile()->getAvatarUrl();
        $viewModel->phone = $user->getProfile()->getPhone();
        $viewModel->status = $user->getStatus()->value;
        $viewModel->roles = $this->roleMapper->toDtoList($user->getRoles());
        $viewModel->createdAt = $user->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $viewModel->updatedAt = $user->getUpdatedAt()->format(\DateTimeInterface::ATOM);
        $viewModel->lastLoginAt = $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM);
        $viewModel->emailVerified = $user->isEmailVerified();
        $viewModel->twoFactorEnabled = $user->isTwoFactorEnabled();

        return $viewModel;
    }
}
