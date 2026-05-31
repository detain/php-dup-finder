<?php

declare(strict_types=1);

namespace App\Domain\Shared\Policy;

/**
 * Centralized business policy registry.
 * Single source of truth for all business rules,
 * eliminating duplication across wikis, tickets, and code comments.
 */
final class BusinessPolicyRegistry
{
    private static array $policies = [];

    public static function register(string $policyName, array $rules): void
    {
        self::$policies[$policyName] = $rules;
    }

    public static function get(string $policyName): array
    {
        return self::$policies[$policyName] ?? [];
    }
}
