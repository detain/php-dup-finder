<?php
declare(strict_types=1);

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

final class SecurityConfigLoader
{
    public const DEFAULT_MIN_PASSWORD_LENGTH = 8;
    public const DEFAULT_MAX_PASSWORD_LENGTH = 128;
    public const DEFAULT_PASSWORD_MAX_AGE_DAYS = 90;
    public const DEFAULT_LOCKOUT_THRESHOLD = 5;
    public const DEFAULT_LOCKOUT_DURATION_MINUTES = 15;

    private array $config;

    public function __construct(string $configPath)
    {
        $this->config = Yaml::parseFile($configPath);
    }

    public function getPasswordPolicy(): array
    {
        $policy = $this->config['security']['password_policy'] ?? [];

        return [
            'min_length' => $policy['min_length'] ?? self::DEFAULT_MIN_PASSWORD_LENGTH,
            'max_length' => $policy['max_length'] ?? self::DEFAULT_MAX_PASSWORD_LENGTH,
            'require_uppercase' => $policy['require_uppercase'] ?? true,
            'require_lowercase' => $policy['require_lowercase'] ?? true,
            'require_digit' => $policy['require_digit'] ?? true,
            'require_special' => $policy['require_special'] ?? true,
            'max_age_days' => $policy['max_age_days'] ?? self::DEFAULT_PASSWORD_MAX_AGE_DAYS,
            'prevent_reuse' => $policy['prevent_reuse'] ?? 5,
            'common_passwords_check' => $policy['common_passwords_check'] ?? true,
        ];
    }

    public function getAccountLockoutPolicy(): array
    {
        $lockout = $this->config['security']['account_lockout'] ?? [];

        return [
            'enabled' => $lockout['enabled'] ?? true,
            'threshold' => $lockout['threshold'] ?? self::DEFAULT_LOCKOUT_THRESHOLD,
            'duration_minutes' => $lockout['duration_minutes'] ?? self::DEFAULT_LOCKOUT_DURATION_MINUTES,
            'reset_after_minutes' => $lockout['reset_after_minutes'] ?? 30,
        ];
    }

    public function validatePasswordAgainstPolicy(string $password): ValidationResult
    {
        $policy = $this->getPasswordPolicy();
        $errors = [];

        if (strlen($password) < $policy['min_length']) {
            $errors[] = "Password must be at least {$policy['min_length']} characters";
        }

        if (strlen($password) > $policy['max_length']) {
            $errors[] = "Password cannot exceed {$policy['max_length']} characters";
        }

        if ($policy['require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }

        if ($policy['require_lowercase'] && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }

        if ($policy['require_digit'] && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one digit';
        }

        if ($policy['require_special'] && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }

        if ($policy['common_passwords_check'] && $this->isCommonPassword($password)) {
            $errors[] = 'This password is too common and cannot be used';
        }

        return new ValidationResult(
            valid: empty($errors),
            errors: $errors
        );
    }

    private function isCommonPassword(string $password): bool
    {
        $commonPasswords = [
            'password', 'password123', 'password1234', '12345678',
            '123456789', 'qwertyuiop', 'qwerty123', 'letmein123',
            'welcome123', 'admin12345', 'login123456', 'monkey123',
            'dragon123', 'master123', 'hello123', 'shadow123'
        ];

        return in_array(strtolower($password), $commonPasswords, true);
    }
}
