<?php

declare(strict_types=1);

namespace App\Validation\Subscriber;

use App\Entity\Subscriber;
use App\Repository\SubscriberRepository;
use App\Service\PasswordValidator;
use Psr\Log\LoggerInterface;

final class SubscriberValidationService
{
    public function __construct(
        private readonly SubscriberRepository $subscriberRepository,
        private readonly PasswordValidator $passwordValidator,
        private readonly LoggerInterface $logger,
    ) {}

    public function validateRegistrationData(array $data): array
    {
        $errors = [];

        if (empty($data['email'])) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        } elseif ($this->subscriberRepository->existsByEmail($data['email'])) {
            $errors['email'] = 'Email already registered';
        }

        if (empty($data['password'])) {
            $errors['password'] = 'Password is required';
        } else {
            $passwordErrors = $this->passwordValidator->validate($data['password']);
            if (!empty($passwordErrors)) {
                $errors['password'] = implode(', ', $passwordErrors);
            }
        }

        if (empty($data['first_name'])) {
            $errors['first_name'] = 'First name is required';
        } elseif (strlen($data['first_name']) < 2) {
            $errors['first_name'] = 'First name must be at least 2 characters';
        }

        if (empty($data['last_name'])) {
            $errors['last_name'] = 'Last name is required';
        } elseif (strlen($data['last_name']) < 2) {
            $errors['last_name'] = 'Last name must be at least 2 characters';
        }

        $this->logger->debug('Subscriber registration validation completed', [
            'errors_count' => count($errors),
        ]);

        return $errors;
    }

    public function validateProfileUpdate(Subscriber $subscriber, array $data): array
    {
        $errors = [];

        if (isset($data['email']) && $data['email'] !== $subscriber->getEmail()) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            } elseif ($this->subscriberRepository->existsByEmail($data['email'])) {
                $errors['email'] = 'Email already in use';
            }
        }

        if (isset($data['phone'])) {
            if (!preg_match('/^\+?[1-9]\d{6,14}$/', $data['phone'])) {
                $errors['phone'] = 'Invalid phone number format';
            }
        }

        if (isset($data['website'])) {
            if (!filter_var($data['website'], FILTER_VALIDATE_URL)) {
                $errors['website'] = 'Invalid website URL';
            }
        }

        return $errors;
    }
}
