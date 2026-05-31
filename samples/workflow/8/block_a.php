<?php
declare(strict_types=1);

namespace App\User\Account;

use App\Domain\Entity\User;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Service\EmailServiceInterface;
use App\Domain\Service\AuthServiceInterface;
use App\Domain\Service\AnalyticsServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class UserRegistrationWorkflow
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private EmailServiceInterface $emailService,
        private AuthServiceInterface $authService,
        private AnalyticsServiceInterface $analyticsService,
        private LoggerInterface $logger,
    ) {}

    public function registerUser(array $userData): User
    {
        $this->logger->info('Starting user registration workflow', ['email' => $userData['email'] ?? 'unknown']);

        $this->validateUserData($userData);

        $this->checkExistingUser($userData['email']);

        $user = $this->createUser($userData);

        $this->hashPassword($user, $userData['password']);

        $this->saveUser($user);

        $this->createUserProfile($user);

        $this->sendVerificationEmail($user);

        $this->setupAnalytics($user);

        $this->sendWelcomeEmail($user);

        $this->recordAuditEvent($user, 'user_registered');

        $this->logger->info('User registration workflow completed', ['user_id' => $user->getId()->toString()]);

        return $user;
    }

    private function validateUserData(array $userData): void
    {
        if (empty($userData['email'])) {
            throw new \RuntimeException("Email is required");
        }

        if (!filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException("Invalid email format");
        }

        if (empty($userData['password'])) {
            throw new \RuntimeException("Password is required");
        }

        if (strlen($userData['password']) < 8) {
            throw new \RuntimeException("Password must be at least 8 characters");
        }

        if (empty($userData['first_name'])) {
            throw new \RuntimeException("First name is required");
        }

        if (empty($userData['last_name'])) {
            throw new \RuntimeException("Last name is required");
        }

        $this->logger->debug('User data validation passed');
    }

    private function checkExistingUser(string $email): void
    {
        $existingUser = $this->userRepository->findByEmail($email);
        if ($existingUser !== null) {
            throw new \RuntimeException("User with email {$email} already exists");
        }

        $this->logger->debug('No existing user found', ['email' => $email]);
    }

    private function createUser(array $userData): User
    {
        $user = new User();
        $user->setEmail($userData['email']);
        $user->setFirstName($userData['first_name']);
        $user->setLastName($userData['last_name']);
        $user->setStatus('pending_verification');
        $user->setCreatedAt(new \DateTimeImmutable());

        $this->logger->debug('User entity created');

        return $user;
    }

    private function hashPassword(User $user, string $password): void
    {
        $hashedPassword = $this->authService->hashPassword($password);
        $user->setPasswordHash($hashedPassword);

        $this->logger->debug('Password hashed');
    }

    private function saveUser(User $user): void
    {
        $this->userRepository->save($user);

        $this->logger->debug('User saved', ['user_id' => $user->getId()->toString()]);
    }

    private function createUserProfile(User $user): void
    {
        $this->authService->createProfile($user->getId()->toString(), [
            'display_name' => $user->getFirstName() . ' ' . $user->getLastName(),
            'timezone' => 'UTC',
            'language' => 'en',
        ]);

        $this->logger->debug('User profile created', ['user_id' => $user->getId()->toString()]);
    }

    private function sendVerificationEmail(User $user): void
    {
        $token = $this->authService->generateEmailVerificationToken($user->getId()->toString());

        $this->emailService->sendTemplate(
            $user->getEmail(),
            'email_verification',
            [
                'user_name' => $user->getFirstName(),
                'verification_link' => "https://example.com/verify-email?token={$token}",
            ]
        );

        $this->recordAuditEvent($user, 'verification_email_sent');
        $this->logger->debug('Verification email sent', ['user_id' => $user->getId()->toString()]);
    }

    private function setupAnalytics(User $user): void
    {
        $this->analyticsService->identifyUser($user->getId()->toString(), [
            'email' => $user->getEmail(),
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
            'registered_at' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'registration_method' => 'email',
        ]);

        $this->analyticsService->trackEvent($user->getId()->toString(), 'user_registered', [
            'method' => 'email',
        ]);

        $this->logger->debug('Analytics setup', ['user_id' => $user->getId()->toString()]);
    }

    private function sendWelcomeEmail(User $user): void
    {
        $this->emailService->sendTemplate(
            $user->getEmail(),
            'welcome',
            [
                'user_name' => $user->getFirstName(),
                'dashboard_link' => 'https://example.com/dashboard',
            ]
        );

        $this->recordAuditEvent($user, 'welcome_email_sent');
        $this->logger->debug('Welcome email sent', ['user_id' => $user->getId()->toString()]);
    }

    private function recordAuditEvent(User $user, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'user_id' => $user->getId()->toString(),
            'email' => $user->getEmail(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
