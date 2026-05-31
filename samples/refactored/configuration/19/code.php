<?php

declare(strict_types=1);

namespace App\Infrastructure\Configuration;

use App\Attributes\Configuration;

#[Configuration('validation')]
final class ValidationConfig
{
    public function __construct(
        public readonly int $minPasswordLength = 8,
        public readonly int $maxPasswordLength = 128,
        public readonly bool $requireUppercase = true,
        public readonly bool $requireLowercase = true,
        public readonly bool $requireNumber = true,
        public readonly bool $requireSpecial = true,
        public readonly int $minUsernameLength = 3,
        public readonly int $maxUsernameLength = 32,
        public readonly int $minNameLength = 2,
        public readonly int $maxNameLength = 100,
        public readonly int $emailMaxLength = 255,
    ) {}

    public function getPasswordRules(): array
    {
        return [
            'min:' . $this->minPasswordLength,
            'max:' . $this->maxPasswordLength,
            $this->requireUppercase ? 'regex:/[A-Z]/' : null,
            $this->requireLowercase ? 'regex:/[a-z]/' : null,
            $this->requireNumber ? 'regex:/[0-9]/' : null,
            $this->requireSpecial ? 'regex:/[!@#$%^&*()]/' : null,
        ];
    }
}

#[Configuration('product')]
final class ProductValidationConfig
{
    public function __construct(
        public readonly int $minNameLength = 3,
        public readonly int $maxNameLength = 255,
        public readonly float $minPrice = 0.01,
        public readonly float $maxPrice = 999999.99,
        public readonly int $priceDecimalPlaces = 2,
        public readonly int $minQuantity = 0,
        public readonly int $maxQuantity = 1000000,
    ) {}
}

trait HasValidationRules
{
    protected abstract function getValidationConfig(): ValidationConfig;

    protected function validatePassword(string $password): array
    {
        $config = $this->getValidationConfig();
        $errors = [];

        if (strlen($password) < $config->minPasswordLength) {
            $errors[] = "Password must be at least {$config->minPasswordLength} characters";
        }

        if ($config->requireUppercase && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain an uppercase letter';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }
}
