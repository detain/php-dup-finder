<?php
declare(strict_types=1);

namespace Billing\Authentication;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;

final class SignupHandler
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly PasswordHasherFactory $hasherFactory,
        private readonly LoggerInterface $logger
    ) {}

    public function createAccount(Request $request): JsonResponse
    {
        $email = $request->request->get('email', '');
        $password = $request->request->get('password', '');
        $passwordConfirm = $request->request->get('password_confirmation', '');

        // Validate password strength
        $passwordErrors = $this->validatePasswordStrength($password);
        if (!empty($passwordErrors)) {
            $this->logger->warning('Signup blocked: weak password', [
                'email' => substr($email, 0, 3) . '***',
                'errors' => $passwordErrors
            ]);
            return $this->json(['error' => implode('. ', $passwordErrors)], 400);
        }

        // Confirm passwords match
        if ($password !== $passwordConfirm) {
            return $this->json(['error' => 'Passwords do not match'], 400);
        }

        // Create the account
        $account = new UserAccount();
        $account->setEmail(strtolower(trim($email)));
        $account->setPassword($this->hasherFactory->getPasswordHasher()->hash($password));
        $account->setStatus(UserAccount::STATUS_PENDING);
        $account->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        $this->logger->info('Account created', ['account_id' => $account->getId()]);

        return $this->json(['message' => 'Account created successfully'], 201);
    }

    private function validatePasswordStrength(string $password): array
    {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }

        if (strlen($password) > 128) {
            $errors[] = 'Password must not exceed 128 characters';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one digit';
        }

        if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }

        // Check for common patterns
        $commonPasswords = ['Password123!', 'Welcome1!', 'Admin123!', 'User1234!'];
        if (in_array($password, $commonPasswords, true)) {
            $errors[] = 'This password is too common. Please choose a more unique password';
        }

        // Check for keyboard patterns
        if (preg_match('/^(.)\1+$/', $password)) {
            $errors[] = 'Password cannot contain repeated characters';
        }

        return $errors;
    }
}
