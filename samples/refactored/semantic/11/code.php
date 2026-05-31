<?php
declare(strict_types=1);

namespace Commerce\Shared;

interface AgeBasedAccessRule
{
    public function getMinimumAge(): int;
    public function evaluate(int $userAge): bool;
}

final class GeneralAccessRule implements AgeBasedAccessRule
{
    public function getMinimumAge(): int
    {
        return 18;
    }

    public function evaluate(int $userAge): bool
    {
        return $userAge >= $this->getMinimumAge();
    }
}

final class RestrictedAccessRule implements AgeBasedAccessRule
{
    public function getMinimumAge(): int
    {
        return 21;
    }

    public function evaluate(int $userAge): bool
    {
        return $userAge >= $this->getMinimumAge();
    }
}

final class YouthAccessRule implements AgeBasedAccessRule
{
    public function getMinimumAge(): int
    {
        return 13;
    }

    public function evaluate(int $userAge): bool
    {
        return $userAge >= $this->getMinimumAge();
    }
}

final class AgeVerificationService
{
    private const AGE_RULES = [
        'general' => 18,
        'restricted' => 21,
        'youth' => 13,
    ];

    public function canAccess(int $age, string $accessLevel): bool
    {
        $minimumAge = self::AGE_RULES[$accessLevel] ?? PHP_INT_MAX;

        return $age >= $minimumAge;
    }

    public function requiresVerification(int $age, string $accessLevel): bool
    {
        return !$this->canAccess($age, $accessLevel);
    }
}
