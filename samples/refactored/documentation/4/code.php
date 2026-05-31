<?php

declare(strict_types=1);

namespace App\Domain\Shared\Error;

/**
 * Centralized error code registry.
 * Single source of truth for all error codes, messages, and metadata.
 */
final class ErrorCodeRegistry
{
    private static array $errors = [];

    public static function register(string $code, array $config): void
    {
        self::$errors[$code] = $config;
    }

    public static function getUserMessage(string $code): string
    {
        return self::$errors[$code]['user_message'] ?? 'An error occurred.';
    }

    public static function getLogLevel(string $code): string
    {
        return self::$errors[$code]['log_level'] ?? 'ERROR';
    }

    public static function isRetryable(string $code): bool
    {
        return self::$errors[$code]['retryable'] ?? false;
    }
}
