<?php

declare(strict_types=1);

namespace App\Users\Calculations;

use App\Exceptions\AgeCalculationException;
use DateTime;
use DateInterval;

final class AgeCalculator
{
    private const MIN_AGE = 0;
    private const MAX_AGE = 150;

    public function calculateAge(DateTime $dateOfBirth, ?DateTime $referenceDate = null): int
    {
        $reference = $referenceDate ?? new DateTime();
        $reference->setTime(0, 0, 0);

        $dob = clone $dateOfBirth;
        $dob->setTime(0, 0, 0);

        if ($dob > $reference) {
            throw new AgeCalculationException('Date of birth cannot be in the future');
        }

        $interval = $dob->diff($reference);

        if ($interval->y > self::MAX_AGE) {
            throw new AgeCalculationException('Invalid date of birth - age exceeds maximum');
        }

        return $interval->y;
    }

    public function calculateAgeInMonths(DateTime $dateOfBirth, ?DateTime $referenceDate = null): int
    {
        $reference = $referenceDate ?? new DateTime();
        $dob = clone $dateOfBirth;

        $interval = $dob->diff($reference);

        return ($interval->y * 12) + $interval->m;
    }

    public function calculateAgeInDays(DateTime $dateOfBirth, ?DateTime $referenceDate = null): int
    {
        $reference = $referenceDate ?? new DateTime();
        $dob = clone $dateOfBirth;

        $interval = $dob->diff($reference);

        return $interval->days;
    }

    public function calculateExactAge(DateTime $dateOfBirth, ?DateTime $referenceDate = null): array
    {
        $reference = $referenceDate ?? new DateTime();
        $dob = clone $dateOfBirth;

        $interval = $dob->diff($reference);

        return [
            'years' => $interval->y,
            'months' => $interval->m,
            'days' => $interval->d,
            'total_days' => $interval->days,
            'total_months' => ($interval->y * 12) + $interval->m,
        ];
    }

    public function isAdult(DateTime $dateOfBirth, int $adultAge = 18, ?DateTime $referenceDate = null): bool
    {
        return $this->calculateAge($dateOfBirth, $referenceDate) >= $adultAge;
    }

    public function isMinor(DateTime $dateOfBirth, int $ageOfMajority = 18, ?DateTime $referenceDate = null): bool
    {
        return !$this->isAdult($dateOfBirth, $ageOfMajority, $referenceDate);
    }

    public function isSenior(DateTime $dateOfBirth, int $seniorAge = 65, ?DateTime $referenceDate = null): bool
    {
        return $this->calculateAge($dateOfBirth, $referenceDate) >= $seniorAge;
    }

    public function isTeenager(DateTime $dateOfBirth, ?DateTime $referenceDate = null): bool
    {
        $age = $this->calculateAge($dateOfBirth, $referenceDate);

        return $age >= 13 && $age <= 19;
    }

    public function getAgeGroup(DateTime $dateOfBirth, ?DateTime $referenceDate = null): string
    {
        $age = $this->calculateAge($dateOfBirth, $referenceDate);

        if ($age < 0) {
            return 'invalid';
        }

        if ($age < 1) {
            return 'infant';
        }

        if ($age < 3) {
            return 'toddler';
        }

        if ($age < 5) {
            return 'preschool';
        }

        if ($age < 13) {
            return 'child';
        }

        if ($age < 20) {
            return 'teenager';
        }

        if ($age < 30) {
            return 'young_adult';
        }

        if ($age < 65) {
            return 'adult';
        }

        if ($age < 75) {
            return 'middle_aged';
        }

        return 'senior';
    }

    public function calculateBirthDateFromAge(int $age, ?DateTime $referenceDate = null): DateTime
    {
        if ($age < self::MIN_AGE || $age > self::MAX_AGE) {
            throw new AgeCalculationException("Age must be between " . self::MIN_AGE . " and " . self::MAX_AGE);
        }

        $reference = $referenceDate ?? new DateTime();

        return $reference->modify("-{$age} years");
    }

    public function getNextBirthday(DateTime $dateOfBirth, ?DateTime $referenceDate = null): DateTime
    {
        $reference = $referenceDate ?? new DateTime();
        $reference->setTime(0, 0, 0);

        $birthday = DateTime::createFromFormat(
            'Y-m-d',
            $reference->format('Y') . '-' . $dateOfBirth->format('m-d')
        );

        if ($birthday === false) {
            throw new AgeCalculationException('Invalid date of birth');
        }

        $birthday->setTime(0, 0, 0);

        if ($birthday <= $reference) {
            $birthday->modify('+1 year');
        }

        return $birthday;
    }

    public function getDaysUntilBirthday(DateTime $dateOfBirth, ?DateTime $referenceDate = null): int
    {
        $nextBirthday = $this->getNextBirthday($dateOfBirth, $referenceDate);
        $reference = $referenceDate ?? new DateTime();

        $interval = $reference->diff($nextBirthday);

        return $interval->days;
    }

    public function validateDateOfBirth(DateTime $dateOfBirth, ?DateTime $referenceDate = null): void
    {
        $reference = $referenceDate ?? new DateTime();
        $reference->setTime(0, 0, 0);

        if ($dateOfBirth > $reference) {
            throw new AgeCalculationException('Date of birth cannot be in the future');
        }

        $age = $this->calculateAge($dateOfBirth, $reference);

        if ($age < self::MIN_AGE || $age > self::MAX_AGE) {
            throw new AgeCalculationException('Date of birth produces invalid age');
        }
    }
}
