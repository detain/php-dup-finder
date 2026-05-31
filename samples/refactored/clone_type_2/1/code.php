<?php

declare(strict_types=1);

namespace App\Shared;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Service\PasswordHasher;
use App\Service\TokenGenerator;
use App\Event\AccountCreatedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class AccountRegistrationService
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly PasswordHasher $passwordHasher,
        private readonly TokenGenerator $tokenGenerator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function register(array $data, string $entityClass): Account
    {
        $email = $this->sanitizeEmail($data['email'] ?? $data['email_address'] ?? '');
        $password = $data['password'] ?? $data['code'] ?? '';
        $name = $this->sanitizeName($data['name'] ?? $data['full_name'] ?? $data['display_name'] ?? '');
        $identifier = $this->generateUniqueIdentifier($name, $email);

        $this->validateEmailIsUnique($email);
        $this->validatePasswordStrength($password);

        $hashedPassword = $this->passwordHasher->hash($password);
        $verificationToken = $this->tokenGenerator->generateSecureToken();

        $account = new $entityClass();
        $account->setEmail($email);
        $account->setPassword($hashedPassword);
        $account->setName($name);
        $account->setIdentifier($identifier);
        $account->setVerificationToken($verificationToken);
        $account->setStatus('pending_verification');
        $account->setCreatedAt(new \DateTimeImmutable());

        $this->accountRepository->save($account);

        $this->eventDispatcher->dispatch(
            new AccountCreatedEvent($account),
            AccountCreatedEvent::NAME
        );

        $this->logger->info('Account registered successfully', [
            'account_id' => $account->getId(),
            'email' => $this->maskEmail($email),
        ]);

        return $account;
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

    private function generateUniqueIdentifier(string $name, string $email): string
    {
        $baseIdentifier = strtolower(explode('@', $email)[0]);
        $namePart = strtolower(str_replace(' ', '', $name));
        $identifier = $namePart ?: $baseIdentifier;

        $counter = 1;
        $candidate = $identifier;
        while ($this->accountRepository->existsByIdentifier($candidate)) {
            $candidate = $identifier . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function validateEmailIsUnique(string $email): void
    {
        if ($this->accountRepository->existsByEmail($email)) {
            $this->logger->warning('Registration attempt with existing email', [
                'email' => $this->maskEmail($email),
            ]);
            throw new \InvalidArgumentException('Email address is already registered');
        }
    }

    private function validatePasswordStrength(string $password): void
    {
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters long');
        }
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
