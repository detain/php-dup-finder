<?php

declare(strict_types=1);

namespace App\Authentication;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PasswordVerifier;
use App\Service\SessionManager;
use Psr\Log\LoggerInterface;

final class UserAuthenticationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordVerifier $passwordVerifier,
        private readonly SessionManager $sessionManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function authenticate(string $email, string $password): array
    {
        if (empty($email) || empty($password)) {
            throw new \InvalidArgumentException('Email and password are required');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }

        $user = $this->userRepository->findByEmail(strtolower(trim($email)));

        if ($user === null) {
            $this->logger->warning('Authentication failed - user not found', [
                'email' => $this->maskEmail($email),
            ]);
            throw new \InvalidArgumentException('Invalid credentials');
        }

        if ($user->getStatus() === 'locked') {
            $this->logger->warning('Authentication failed - account locked', [
                'user_id' => $user->getId(),
            ]);
            throw new \InvalidArgumentException('Account is locked');
        }

        if ($user->getStatus() === 'pending_verification') {
            $this->logger->warning('Authentication failed - email not verified', [
                'user_id' => $user->getId(),
            ]);
            throw new \InvalidArgumentException('Please verify your email first');
        }

        if ($user->getStatus() !== 'active') {
            $this->logger->warning('Authentication failed - invalid status', [
                'user_id' => $user->getId(),
                'status' => $user->getStatus(),
            ]);
            throw new \InvalidArgumentException('Account is not active');
        }

        if ($user->getFailedLoginAttempts() >= 5) {
            $this->logger->warning('Authentication failed - too many attempts', [
                'user_id' => $user->getId(),
            ]);
            throw new \InvalidArgumentException('Account temporarily locked due to failed attempts');
        }

        if (!$this->passwordVerifier->verify($password, $user->getPasswordHash())) {
            $user->incrementFailedLoginAttempts();
            $this->userRepository->save($user);

            $this->logger->warning('Authentication failed - invalid password', [
                'user_id' => $user->getId(),
                'attempts' => $user->getFailedLoginAttempts(),
            ]);
            throw new \InvalidArgumentException('Invalid credentials');
        }

        if ($user->getPasswordMustBeChanged()) {
            $this->logger->info('User must change password', [
                'user_id' => $user->getId(),
            ]);
            return [
                'user_id' => $user->getId(),
                'require_password_change' => true,
            ];
        }

        $user->resetFailedLoginAttempts();
        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->userRepository->save($user);

        $sessionToken = $this->sessionManager->createSession($user);

        $this->logger->info('User authenticated successfully', [
            'user_id' => $user->getId(),
        ]);

        return [
            'user_id' => $user->getId(),
            'session_token' => $sessionToken,
            'require_password_change' => false,
        ];
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        if ($user->getStatus() === 'locked') {
            throw new \InvalidArgumentException('Account is locked');
        }

        if ($user->getStatus() === 'pending_verification') {
            throw new \InvalidArgumentException('Please verify your email first');
        }

        if ($user->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Account is not active');
        }

        if (!$this->passwordVerifier->verify($currentPassword, $user->getPasswordHash())) {
            throw new \InvalidArgumentException('Current password is incorrect');
        }

        if (strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('New password must be at least 8 characters');
        }

        if (!$this->isPasswordComplex($newPassword)) {
            throw new \InvalidArgumentException('Password must contain uppercase, lowercase, and numbers');
        }

        if ($this->isPasswordReused($newPassword, $user)) {
            throw new \InvalidArgumentException('Password was used recently');
        }

        $user->setPasswordHash($this->passwordVerifier->hash($newPassword));
        $user->setPasswordMustBeChanged(false);
        $user->setPasswordChangedAt(new \DateTimeImmutable());
        $user->resetFailedLoginAttempts();

        $this->userRepository->save($user);

        $this->sessionManager->invalidateOtherSessions($user->getId());

        $this->logger->info('Password changed successfully', [
            'user_id' => $userId,
        ]);

        return true;
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        $local = $parts[0] ?? '';
        return substr($local, 0, 2) . '***';
    }

    private function isPasswordComplex(string $password): bool
    {
        return preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password)
            && preg_match('/[0-9]/', $password);
    }

    private function isPasswordReused(string $password, User $user): bool
    {
        $recentHashes = $user->getRecentPasswordHashes();

        foreach ($recentHashes as $hash) {
            if ($this->passwordVerifier->verify($password, $hash)) {
                return true;
            }
        }

        return false;
    }
}
