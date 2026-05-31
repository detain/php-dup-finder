<?php

declare(strict_types=1);

namespace Acme\Shared\Policy;

use DateTimeImmutable;

final class AdultPolicy
{
    public function __construct(private int $ageOfMajority = 18)
    {
    }

    public function isAdult(DateTimeImmutable $birthDate, ?DateTimeImmutable $asOf = null): bool
    {
        $asOf ??= new DateTimeImmutable('today');
        return (int) $asOf->diff($birthDate)->y >= $this->ageOfMajority;
    }
}

// Usage across modules:
//   $policy = new AdultPolicy();
//   if (!$policy->isAdult($user->birthDate())) { ... }
final class RegistrationController
{
    public function __construct(private AdultPolicy $policy) {}
    public function ensureEligible(DateTimeImmutable $birthDate): void
    {
        if (!$this->policy->isAdult($birthDate)) {
            throw new \DomainException('Users must be at least 18 years old to register.');
        }
    }
}

final class AlcoholPurchaseGuard
{
    public function __construct(private AdultPolicy $policy) {}
    public function ensureEligible(DateTimeImmutable $birthDate): void
    {
        if (!$this->policy->isAdult($birthDate)) {
            throw new \DomainException('Customer cannot purchase age-restricted items.');
        }
    }
}

final class MatureContentGate
{
    public function __construct(private AdultPolicy $policy) {}
    public function ensureEligible(DateTimeImmutable $birthDate): void
    {
        if (!$this->policy->isAdult($birthDate)) {
            throw new \DomainException('This content is not available to minors.');
        }
    }
}
