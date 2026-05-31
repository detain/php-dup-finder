<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BirthDateException;
use DateTime;
use DateInterval;

final class PersonAgeDeterminator
{
    private const MINIMUM_AGE = 0;
    private const MAXIMUM_AGE = 130;

    public function determine(DateTime $birthDate, ?DateTime $currentDate = null): int
    {
        $now = $currentDate ?? new DateTime();
        $now->setTime(0, 0, 0);

        $birth = clone $birthDate;
        $birth->setTime(0, 0, 0);

        if ($birth > $now) {
            throw new BirthDateException('Birth date cannot be after today');
        }

        $span = $birth->diff($now);

        if ($span->y > self::MAXIMUM_AGE) {
            throw new BirthDateException('Calculated age exceeds maximum plausible age');
        }

        return $span->y;
    }

    public function determineInMonths(DateTime $birthDate, ?DateTime $currentDate = null): int
    {
        $now = $currentDate ?? new DateTime();
        $birth = clone $birthDate;

        $elapsed = $birth->diff($now);

        return ($elapsed->y * 12) + $elapsed->m;
    }

    public function determineInDays(DateTime $birthDate, ?DateTime $currentDate = null): int
    {
        $now = $currentDate ?? new DateTime();
        $birth = clone $birthDate;

        return $birth->diff($now)->days;
    }

    public function determineFull(DateTime $birthDate, ?DateTime $currentDate = null): array
    {
        $now = $currentDate ?? new DateTime();
        $birth = clone $birthDate;

        $elapsed = $birth->diff($now);

        return [
            'years' => $elapsed->y,
            'months' => $elapsed->m,
            'days' => $elapsed->d,
            'total_days' => $elapsed->days,
            'total_months' => ($elapsed->y * 12) + $elapsed->m,
        ];
    }

    public function isLegalAdult(DateTime $birthDate, int $threshold = 18, ?DateTime $currentDate = null): bool
    {
        return $this->determine($birthDate, $currentDate) >= $threshold;
    }

    public function isUnderage(DateTime $birthDate, int $threshold = 18, ?DateTime $currentDate = null): bool
    {
        return !$this->isLegalAdult($birthDate, $threshold, $currentDate);
    }

    public function isRetiree(DateTime $birthDate, int $retirementAge = 65, ?DateTime $currentDate = null): bool
    {
        return $this->determine($birthDate, $currentDate) >= $retirementAge;
    }

    public function isInTeenYears(DateTime $birthDate, ?DateTime $currentDate = null): bool
    {
        $age = $this->determine($birthDate, $currentDate);

        return $age >= 13 && $age <= 19;
    }

    public function ageBracket(DateTime $birthDate, ?DateTime $currentDate = null): string
    {
        $age = $this->determine($birthDate, $currentDate);

        if ($age < 0) {
            return 'invalid';
        }

        if ($age < 1) {
            return 'baby';
        }

        if ($age < 3) {
            return 'toddler';
        }

        if ($age < 5) {
            return 'preschool';
        }

        if ($age < 13) {
            return 'school_age_child';
        }

        if ($age < 20) {
            return 'adolescent';
        }

        if ($age < 40) {
            return 'thirtysomething';
        }

        if ($age < 65) {
            return 'middle_aged';
        }

        return 'older_senior';
    }

    public function computeBirthDateFromAge(int $age, ?DateTime $currentDate = null): DateTime
    {
        if ($age < self::MINIMUM_AGE || $age > self::MAXIMUM_AGE) {
            throw new BirthDateException("Age must be between " . self::MINIMUM_AGE . " and " . self::MAXIMUM_AGE);
        }

        $today = $currentDate ?? new DateTime();

        return clone $today->modify("-{$age} years");
    }

    public function findNextBirthday(DateTime $birthDate, ?DateTime $currentDate = null): DateTime
    {
        $today = $currentDate ?? new DateTime();
        $today->setTime(0, 0, 0);

        $thisYearBirthday = DateTime::createFromFormat(
            'Y-m-d',
            $today->format('Y') . '-' . $birthDate->format('m-d')
        );

        if ($thisYearBirthday === false) {
            throw new BirthDateException('Invalid birth date');
        }

        $thisYearBirthday->setTime(0, 0, 0);

        if ($thisYearBirthday <= $today) {
            $thisYearBirthday->modify('+1 year');
        }

        return $thisYearBirthday;
    }

    public function countDaysToBirthday(DateTime $birthDate, ?DateTime $currentDate = null): int
    {
        $next = $this->findNextBirthday($birthDate, $currentDate);
        $today = $currentDate ?? new DateTime();

        return $today->diff($next)->days;
    }

    public function validateBirthDate(DateTime $birthDate, ?DateTime $currentDate = null): void
    {
        $today = $currentDate ?? new DateTime();
        $today->setTime(0, 0, 0);

        $birth = clone $birthDate;
        $birth->setTime(0, 0, 0);

        if ($birth > $today) {
            throw new BirthDateException('Birth date cannot be in the future');
        }

        if ($this->determine($birthDate, $currentDate) > self::MAXIMUM_AGE) {
            throw new BirthDateException('Birth date produces unreasonably high age');
        }
    }
}
