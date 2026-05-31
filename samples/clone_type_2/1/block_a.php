<?php

declare(strict_types=1);

namespace App\Authentication;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PasswordHasher;
use App\Service\TokenGenerator;
use App\Event\UserRegisteredEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class UserRegistrationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordHasher $passwordHasher,
        private readonly TokenGenerator $tokenGenerator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function registerUser(array $registrationData): User
    {
        $email = $this->sanitizeEmail($registrationData['email'] ?? '');
        $plainPassword = $registrationData['password'] ?? '';
        $name = $this->sanitizeName($registrationData['name'] ?? '');
        $username = $this->generateUsername($name, $email);

        if ($this->userRepository->existsByEmail($email)) {
            $this->logger->warning('Registration attempt with existing email', [
                'email' => $this->maskEmail($email),
            ]);
            throw new \InvalidArgumentException('Email address is already registered');
        }

        if (strlen($plainPassword) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters long');
        }

        $hashedPassword = $this->passwordHasher->hash($plainPassword);
        $verificationToken = $this->tokenGenerator->generateSecureToken();

        $user = new User();
        $user->setEmail($email);
        $user->setPassword($hashedPassword);
        $user->setName($name);
        $user->setUsername($username);
        $user->setEmailVerificationToken($verificationToken);
        $user->setStatus('pending_verification');
        $user->setCreatedAt(new \DateTimeImmutable());

        $this->userRepository->save($user);

        $this->eventDispatcher->dispatch(
            new UserRegisteredEvent($user),
            UserRegisteredEvent::NAME
        );

        $this->logger->info('New user registered successfully', [
            'user_id' => $user->getId(),
            'email' => $this->maskEmail($email),
        ]);

        return $user;
    }

    private function sanitizeEmail(string $email): string
    {
        $email = trim(strtolower($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }
        return $email;
    }

    private function sanitizeName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    private function generateUsername(string $name, string $email): string
    {
        $baseUsername = strtolower(explode('@', $email)[0]);
        $namePart = strtolower(str_replace(' ', '', $name));
        $username = $namePart ?: $baseUsername;

        $counter = 1;
        $candidate = $username;
        while ($this->userRepository->existsByUsername($candidate)) {
            $candidate = $username . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        $local = $parts[0];
        $domain = $parts[1] ?? '';
        $maskedLocal = substr($local, 0, 2) . '***';
        return $maskedLocal . '@' . $domain;
    }
}
