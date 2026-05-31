<?php

declare(strict_types=1);

namespace App\Domain\UserManagement;

use Psr\Log\LoggerInterface;
use App\Domain\UserManagement\Entity\User;
use App\Domain\UserManagement\Repository\UserRepositoryInterface;
use App\Domain\UserManagement\Event\UserRegisteredEvent;
use App\Infrastructure\Messaging\EventDispatcher;

/**
 * User management service handling user lifecycle.
 * The LoggerInterface is manually injected here, duplicated from
 * OrderService and other services.
 */
class UserService
{
    private LoggerInterface $logger;
    private UserRepositoryInterface $userRepository;
    private EventDispatcher $eventDispatcher;

    public function __construct(
        UserRepositoryInterface $userRepository,
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger
    ) {
        $this->userRepository = $userRepository;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
    }

    public function registerUser(array $userData): User
    {
        $this->logger->info('Registering new user', [
            'email' => $userData['email'],
            'source' => $userData['source'] ?? 'direct',
        ]);

        try {
            $existingUser = $this->userRepository->findByEmail($userData['email']);

            if ($existingUser !== null) {
                throw new EmailAlreadyExistsException(
                    'A user with this email address already exists'
                );
            }

            $user = new User(
                email: $userData['email'],
                firstName: $userData['first_name'],
                lastName: $userData['last_name'],
                passwordHash: password_hash($userData['password'], PASSWORD_ARGON2ID),
            );

            if (isset($userData['phone'])) {
                $user->setPhoneNumber($userData['phone']);
            }

            $savedUser = $this->userRepository->save($user);

            $this->eventDispatcher->dispatch(
                new UserRegisteredEvent($savedUser)
            );

            $this->logger->info('User registered successfully', [
                'user_id' => $savedUser->getId()->toString(),
                'email' => $savedUser->getEmail(),
            ]);

            return $savedUser;

        } catch (\Exception $e) {
            $this->logger->error('Failed to register user', [
                'email' => $userData['email'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function updateProfile(string $userId, array $profileData): User
    {
        $this->logger->info('Updating user profile', [
            'user_id' => $userId,
        ]);

        $user = $this->userRepository->findById($userId);

        if (isset($profileData['first_name'])) {
            $user->setFirstName($profileData['first_name']);
        }

        if (isset($profileData['last_name'])) {
            $user->setLastName($profileData['last_name']);
        }

        if (isset($profileData['phone'])) {
            $user->setPhoneNumber($profileData['phone']);
        }

        $savedUser = $this->userRepository->save($user);

        $this->logger->info('Profile updated successfully', [
            'user_id' => $userId,
        ]);

        return $savedUser;
    }

    public function deactivateUser(string $userId, string $reason): User
    {
        $this->logger->info('Deactivating user', [
            'user_id' => $userId,
            'reason' => $reason,
        ]);

        $user = $this->userRepository->findById($userId);

        if ($user->isSystemAdmin()) {
            throw new CannotDeactivateAdminException(
                'System administrators cannot be deactivated'
            );
        }

        $user->deactivate($reason);
        $savedUser = $this->userRepository->save($user);

        $this->logger->info('User deactivated successfully', [
            'user_id' => $userId,
        ]);

        return $savedUser;
    }
}
