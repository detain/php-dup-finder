<?php

declare(strict_types=1);

namespace App\Api\Documentation;

/**
 * Centralized API parameter documentation.
 * Single source of truth for all endpoint parameters,
 * eliminating duplication across OpenAPI, docblocks, and SDK docs.
 */
final class ApiParameterRegistry
{
    private static array $parameters = [];

    public static function register(string $endpoint, string $param, array $config): void
    {
        self::$parameters[$endpoint][$param] = $config;
    }

    public static function getRequiredParams(string $endpoint): array
    {
        return array_filter(
            self::$parameters[$endpoint] ?? [],
            fn($p) => $p['required'] ?? false
        );
    }
}
