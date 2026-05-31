<?php
declare(strict_types=1);

namespace App\User\Api;

use Symfony\Component\Validator\Constraints as Assert;

final class UserApiModel
{
    #[Assert\NotBlank(message: 'Email is required')]
    #[Assert\Email(message: 'Invalid email format')]
    #[Assert\Length(max: 254, maxMessage: 'Email cannot exceed 254 characters')]
    public string $email;

    #[Assert\NotBlank(message: 'First name is required')]
    #[Assert\Length(min: 1, max: 100, minMessage: 'First name is required', maxMessage: 'First name cannot exceed 100 characters')]
    public string $firstName;

    #[Assert\NotBlank(message: 'Last name is required')]
    #[Assert\Length(min: 1, max: 100, minMessage: 'Last name is required', maxMessage: 'Last name cannot exceed 100 characters')]
    public string $lastName;

    #[Assert\NotBlank(message: 'Role is required')]
    #[Assert\Choice(choices: ['user', 'editor', 'admin'], message: 'Invalid role')]
    public string $role;

    #[Assert\Url(message: 'Invalid avatar URL')]
    #[Assert\Length(max: 500, maxMessage: 'Avatar URL cannot exceed 500 characters')]
    public ?string $avatarUrl = null;

    #[Assert\NotNull(message: 'Active status is required')]
    public bool $isActive;

    public string $createdAt;
    public string $updatedAt;
    public ?string $lastLoginAt = null;

    public static function fromEntity(\App\User\Entity\User $user): self
    {
        $model = new self();
        $model->email = $user->getEmail();
        $model->firstName = $user->getFirstName();
        $model->lastName = $user->getLastName();
        $model->role = $user->getRole();
        $model->avatarUrl = $user->getAvatarUrl();
        $model->isActive = $user->isActive();
        $model->createdAt = $user->getCreatedAt()->format('c');
        $model->updatedAt = $user->getUpdatedAt()->format('c');
        $model->lastLoginAt = $user->getLastLoginAt()?->format('c');

        return $model;
    }

    public function toArray(): array
    {
        return [
            'type' => 'users',
            'id' => $this->getUserId(),
            'attributes' => [
                'email' => $this->email,
                'first_name' => $this->firstName,
                'last_name' => $this->lastName,
                'role' => $this->role,
                'avatar_url' => $this->avatarUrl,
                'is_active' => $this->isActive,
                'created_at' => $this->createdAt,
                'updated_at' => $this->updatedAt,
                'last_login_at' => $this->lastLoginAt
            ]
        ];
    }

    public function toJsonApi(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    private function getUserId(): ?string
    {
        return null;
    }
}
