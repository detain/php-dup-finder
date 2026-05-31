<?php
declare(strict_types=1);

namespace App\Security\Policy;

final class PasswordPolicy
{
    private const DEFAULT_MIN_LENGTH = 8;
    private const DEFAULT_MAX_LENGTH = 128;
    private const DEFAULT_MAX_AGE_DAYS = 90;

    public function __construct(
        public readonly int $minLength = self::DEFAULT_MIN_LENGTH,
        public readonly int $maxLength = self::DEFAULT_MAX_LENGTH,
        public readonly bool $requireUppercase = true,
        public readonly bool $requireLowercase = true,
        public readonly bool $requireDigit = true,
        public readonly bool $requireSpecial = true,
        public readonly int $maxAgeDays = self::DEFAULT_MAX_AGE_DAYS,
        public readonly int $preventReuseCount = 5,
        public readonly bool $checkCommonPasswords = true
    ) {}

    public static function fromConfig(array $config): self
    {
        return new self(
            minLength: $config['min_length'] ?? self::DEFAULT_MIN_LENGTH,
            maxLength: $config['max_length'] ?? self::DEFAULT_MAX_LENGTH,
            requireUppercase: $config['require_uppercase'] ?? true,
            requireLowercase: $config['require_lowercase'] ?? true,
            requireDigit: $config['require_digit'] ?? true,
            requireSpecial: $config['require_special'] ?? true,
            maxAgeDays: $config['max_age_days'] ?? self::DEFAULT_MAX_AGE_DAYS,
            preventReuseCount: $config['prevent_reuse'] ?? 5,
            checkCommonPasswords: $config['common_passwords_check'] ?? true
        );
    }

    public function validate(string $password): PasswordValidationResult
    {
        $errors = [];

        if (strlen($password) < $this->minLength) {
            $errors[] = "Password must be at least {$this->minLength} characters";
        }

        if (strlen($password) > $this->maxLength) {
            $errors[] = "Password cannot exceed {$this->maxLength} characters";
        }

        if ($this->requireUppercase && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }

        if ($this->requireLowercase && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }

        if ($this->requireDigit && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one digit';
        }

        if ($this->requireSpecial && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }

        if ($this->checkCommonPasswords && $this->isCommonPassword($password)) {
            $errors[] = 'This password is too common and cannot be used';
        }

        return new PasswordValidationResult(
            isValid: empty($errors),
            errors: $errors
        );
    }

    private function isCommonPassword(string $password): bool
    {
        $commonPasswords = [
            'password', 'password123', '12345678', 'qwertyui',
            'letmein', 'welcome', 'monkey', 'dragon',
            'master', 'admin123', 'login123'
        ];

        return in_array(strtolower($password), $commonPasswords, true);
    }
}
