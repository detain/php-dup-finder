<?php

namespace App\Services\Users;

use DateTime;

final class AgeConfig
{
    public readonly int $minAge;
    public readonly int $maxAge;
    public readonly int $adultThreshold;
    public readonly int $seniorThreshold;

    public function __construct(
        int $minAge = 0,
        int $maxAge = 120,
        int $adultThreshold = 18,
        int $seniorThreshold = 65
    ) {
        $this->minAge = $minAge;
        $this->maxAge = $maxAge;
        $this->adultThreshold = $adultThreshold;
        $this->seniorThreshold = $seniorThreshold;
    }
}

final class AgeService
{
    private AgeConfig $config;

    public function __construct(AgeConfig $config)
    {
        $this->config = $config;
    }

    public function calculate(DateTime $dob, ?DateTime $reference = null): int
    {
        $ref = $reference ?? new DateTime();
        $ref->setTime(0, 0, 0);
        $birth = clone $dob;
        $birth->setTime(0, 0, 0);

        if ($birth > $ref) {
            throw new \InvalidArgumentException('Date of birth cannot be in the future');
        }

        $age = $birth->diff($ref)->y;

        if ($age < $this->config->minAge || $age > $this->config->maxAge) {
            throw new \InvalidArgumentException('Invalid age range');
        }

        return $age;
    }

    public function isAdult(DateTime $dob, ?DateTime $reference = null): bool
    {
        return $this->calculate($dob, $reference) >= $this->config->adultThreshold;
    }

    public function isSenior(DateTime $dob, ?DateTime $reference = null): bool
    {
        return $this->calculate($dob, $reference) >= $this->config->seniorThreshold;
    }

    public function ageCategory(DateTime $dob, ?DateTime $reference = null): string
    {
        $age = $this->calculate($dob, $reference);

        if ($age < 13) {
            return 'child';
        }

        if ($age < 20) {
            return 'teenager';
        }

        if ($age < 65) {
            return 'adult';
        }

        return 'senior';
    }
}
