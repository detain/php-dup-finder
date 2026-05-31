<?php

declare(strict_types=1);

namespace App\Validation;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Exception\ValidationException;

final class UserValidator
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}

    public function validateCreate(array $data): User
    {
        $errors = [];

        if (empty($data['email'])) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        } elseif ($this->userRepository->findByEmail($data['email']) !== null) {
            $errors['email'] = 'Email already exists';
        }

        if (empty($data['username'])) {
            $errors['username'] = 'Username is required';
        } elseif (strlen($data['username']) < 3) {
            $errors['username'] = 'Username must be at least 3 characters';
        } elseif (strlen($data['username']) > 50) {
            $errors['username'] = 'Username must not exceed 50 characters';
        } elseif ($this->userRepository->findByUsername($data['username']) !== null) {
            $errors['username'] = 'Username already exists';
        }

        if (empty($data['password'])) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($data['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        } elseif (!preg_match('/[A-Z]/', $data['password'])) {
            $errors['password'] = 'Password must contain at least one uppercase letter';
        } elseif (!preg_match('/[a-z]/', $data['password'])) {
            $errors['password'] = 'Password must contain at least one lowercase letter';
        } elseif (!preg_match('/[0-9]/', $data['password'])) {
            $errors['password'] = 'Password must contain at least one number';
        }

        if (isset($data['age']) && $data['age'] < 18) {
            $errors['age'] = 'User must be at least 18 years old';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return new User($data['email'], $data['username'], $data['password'], $data['age'] ?? null);
    }

    public function validateUpdate(User $user, array $data): User
    {
        $errors = [];

        if (isset($data['email']) && $data['email'] !== $user->getEmail()) {
            if (empty($data['email'])) {
                $errors['email'] = 'Email cannot be empty';
            } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            } elseif ($this->userRepository->findByEmail($data['email']) !== null) {
                $errors['email'] = 'Email already exists';
            }
        }

        if (isset($data['username']) && $data['username'] !== $user->getUsername()) {
            if (empty($data['username'])) {
                $errors['username'] = 'Username cannot be empty';
            } elseif (strlen($data['username']) < 3) {
                $errors['username'] = 'Username must be at least 3 characters';
            } elseif ($this->userRepository->findByUsername($data['username']) !== null) {
                $errors['username'] = 'Username already exists';
            }
        }

        if (isset($data['age']) && $data['age'] < 18) {
            $errors['age'] = 'User must be at least 18 years old';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $user->update($data);
    }
}
