<?php

declare(strict_types=1);

namespace App\Validation;

use App\Entity\AccountInterface;
use App\Repository\AccountRepositoryInterface;
use App\Exception\ValidationException;

interface ValidatorInterface
{
    public function validateCreate(array $data): AccountInterface;
    public function validateUpdate(AccountInterface $entity, array $data): AccountInterface;
}

abstract class AbstractValidator implements ValidatorInterface
{
    public function __construct(
        protected readonly AccountRepositoryInterface $repository,
    ) {}

    protected function validateEmail(string $email, ?int $excludeId = null): array
    {
        $errors = [];

        if (empty($email)) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        } elseif ($this->repository->findByEmail($email, $excludeId) !== null) {
            $errors['email'] = 'Email already exists';
        }

        return $errors;
    }

    protected function validatePassword(string $password): array
    {
        $errors = [];

        if (empty($password)) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $errors['password'] = 'Password must contain at least one uppercase letter';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $errors['password'] = 'Password must contain at least one lowercase letter';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'Password must contain at least one number';
        }

        return $errors;
    }

    protected function validateNameField(string $fieldName, string $value, int $minLen, int $maxLen): array
    {
        $errors = [];

        if (empty($value)) {
            $errors[$fieldName] = ucfirst($fieldName) . ' is required';
        } elseif (strlen($value) < $minLen) {
            $errors[$fieldName] = ucfirst($fieldName) . ' must be at least ' . $minLen . ' characters';
        } elseif (strlen($value) > $maxLen) {
            $errors[$fieldName] = ucfirst($fieldName) . ' must not exceed ' . $maxLen . ' characters';
        }

        return $errors;
    }

    protected function throwIfErrors(array $errors): void
    {
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
