<?php

declare(strict_types=1);

namespace App\Domain\Policies;

final class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 32;
    public const COMMON_BLACKLIST = ['password', 'qwerty', '12345678', 'letmein', 'admin123'];

    public static function describe(): string
    {
        return sprintf(
            'Use %d to %d characters, including upper, lower, digit, and symbol.',
            self::MIN_LENGTH,
            self::MAX_LENGTH
        );
    }
}

// Validator consumes the constants:
final class PasswordValidator
{
    public function validate(string $password): array
    {
        $errors = [];
        $len = strlen($password);
        if ($len < PasswordPolicy::MIN_LENGTH || $len > PasswordPolicy::MAX_LENGTH) {
            $errors[] = sprintf('Password must be %d-%d characters.', PasswordPolicy::MIN_LENGTH, PasswordPolicy::MAX_LENGTH);
        }
        if (in_array(strtolower($password), PasswordPolicy::COMMON_BLACKLIST, true)) {
            $errors[] = 'Password is too common.';
        }
        return $errors;
    }
}

// Migration consumes the constant:
// $table->string('password_plain_max_len_check', PasswordPolicy::MAX_LENGTH)->nullable();

// Form builder consumes the constant:
// '<input type="password" minlength="' . PasswordPolicy::MIN_LENGTH . '" maxlength="' . PasswordPolicy::MAX_LENGTH . '">'
// '<p class="hint">' . htmlspecialchars(PasswordPolicy::describe()) . '</p>'
