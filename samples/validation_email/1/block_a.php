<?php
declare(strict_types=1);

namespace Ecommerce\UserRegistration;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Request;

final class RegistrationController
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly EmailQueue $emailQueue,
        private readonly LoggerInterface $logger
    ) {}

    public function register(Request $request): JsonResponse
    {
        $email = $request->request->get('email', '');
        $password = $request->request->get('password', '');
        $firstName = $request->request->get('first_name', '');
        $lastName = $request->request->get('last_name', '');

        // Validate email format and domain
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->logger->warning('Registration failed: invalid email format', [
                'email' => substr($email, 0, 3) . '***',
                'ip' => $request->getClientIp()
            ]);
            return $this->json(['error' => 'Please provide a valid email address'], 400);
        }

        // Check for disposable email domains
        $disposableDomains = $this->getDisposableEmailDomains();
        $emailDomain = strtolower(substr(strrchr($email, '@'), 1));
        if (in_array($emailDomain, $disposableDomains, true)) {
            $this->logger->info('Registration blocked: disposable email domain', [
                'domain' => $emailDomain,
                'ip' => $request->getClientIp()
            ]);
            return $this->json(['error' => 'Disposable email addresses are not allowed'], 400);
        }

        // Validate email length constraints
        if (strlen($email) > 254) {
            return $this->json(['error' => 'Email address exceeds maximum length'], 400);
        }

        // Check for existing user
        $existingUser = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['email' => strtolower($email)]);

        if ($existingUser !== null) {
            $this->logger->info('Registration failed: email already exists', [
                'email' => substr($email, 0, 3) . '***'
            ]);
            return $this->json(['error' => 'An account with this email already exists'], 409);
        }

        // Create new user
        $user = new User();
        $user->setEmail(strtolower($email));
        $user->setFirstName(trim($firstName));
        $user->setLastName(trim($lastName));
        $user->setPasswordHash(password_hash($password, PASSWORD_ARGON2ID));
        $user->setStatus('pending_verification');
        $user->setCreatedAt(new \DateTimeImmutable());
        $user->setLastActivityAt(new \DateTimeImmutable());

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Send verification email
        $this->emailQueue->dispatch(new VerificationEmailJob($user->getId()));

        $this->logger->info('User registered successfully', [
            'user_id' => $user->getId(),
            'email' => substr($email, 0, 3) . '***'
        ]);

        return $this->json([
            'message' => 'Registration successful. Please check your email to verify your account.',
            'user_id' => $user->getId()
        ], 201);
    }

    private function getDisposableEmailDomains(): array
    {
        return [
            'tempmail.com', 'throwaway.email', 'guerrillamail.com',
            'mailinator.com', '10minutemail.com', 'fakeinbox.com',
            'trashmail.com', 'dispostable.com', 'maildrop.cc'
        ];
    }
}
