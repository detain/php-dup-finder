<?php

declare(strict_types=1);

namespace App\Domain\Shared\Error;

/**
 * Centralized error code registry.
 * Single source of truth for all error codes,
 * eliminating duplication across documentation, code, and SDKs.
 */
final class ErrorRegistry
{
    private static array $errors = [];

    public static function register(string $code, array $config): void
    {
        self::$errors[$code] = $config;
    }

    public static function get(string $code): ?array
    {
        return self::$errors[$code] ?? null;
    }

    public static function getHttpStatus(string $code): int
    {
        return self::$errors[$code]['http_status'] ?? 500;
    }

    public static function isRetryable(string $code): bool
    {
        return self::$errors[$code]['retryable'] ?? false;
    }
}
