<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exceptions\ValidationException;

final class PasswordValidator
{
    private array $errors = [];

    public function validate(string $password): bool
    {
        $this->errors = [];

        if (strlen($password) < 8) {
            $this->errors[] = 'Password must be at least 8 characters long.';
        }

        if (strlen($password) > 32) {
            $this->errors[] = 'Password must not exceed 32 characters.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $this->errors[] = 'Password must contain an uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $this->errors[] = 'Password must contain a lowercase letter.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $this->errors[] = 'Password must contain a digit.';
        }

        if (!preg_match('/[\W_]/', $password)) {
            $this->errors[] = 'Password must contain a special character.';
        }

        if (in_array(strtolower($password), $this->commonPasswords(), true)) {
            $this->errors[] = 'Password is too common.';
        }

        return $this->errors === [];
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function assertValid(string $password): void
    {
        if (!$this->validate($password)) {
            throw new ValidationException(implode(' ', $this->errors));
        }
    }

    private function commonPasswords(): array
    {
        return ['password', 'qwerty', '12345678', 'letmein', 'admin123'];
    }
}
