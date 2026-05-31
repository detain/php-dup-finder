<?php

declare(strict_types=1);

namespace Acme\Accounts\Registration;

use Acme\Accounts\Model\User;
use Acme\Accounts\Notification\WelcomeMailer;
use Acme\Accounts\Repository\UserRepository;
use Acme\Accounts\Exception\RegistrationDeniedException;
use DateTimeImmutable;

final class RegistrationController
{
    public function __construct(
        private UserRepository $users,
        private WelcomeMailer $mailer,
    ) {
    }

    public function register(array $payload): User
    {
        $email = (string) ($payload['email'] ?? '');
        $birthDate = new DateTimeImmutable((string) ($payload['birth_date'] ?? ''));
        $today = new DateTimeImmutable('today');

        $age = (int) $today->diff($birthDate)->y;
        if ($age < 18) {
            throw new RegistrationDeniedException(
                'Users must be at least 18 years old to register.'
            );
        }

        if ($this->users->existsByEmail($email)) {
            throw new RegistrationDeniedException('Email already in use.');
        }

        $user = new User(
            email: $email,
            birthDate: $birthDate,
            registeredAt: new DateTimeImmutable(),
        );

        $this->users->save($user);
        $this->mailer->sendWelcome($user);

        return $user;
    }
}
