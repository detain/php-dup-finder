<?php

declare(strict_types=1);

namespace App\Profiles;

use App\Exceptions\DobException;
use DateTime;
use DateInterval;

final class BirthdateProcessor
{
    private const YOUNGEST_VALID = 0;
    private const OLDEST_VALID = 120;

    public function process(DateTime $dob, ?DateTime $asOf = null): int
    {
        $today = $asOf ?? new DateTime();
        $today->setTime(0, 0, 0);

        $birth = clone $dob;
        $birth->setTime(0, 0, 0);

        if ($birth > $today) {
            throw new DobException('Date of birth cannot be after reference date');
        }

        $diff = $birth->diff($today);

        if ($diff->y > self::OLDEST_VALID) {
            throw new DobException('Resulting age exceeds maximum valid age');
        }

        return $diff->y;
    }

    public function processMonths(DateTime $dob, ?DateTime $asOf = null): int
    {
        $today = $asOf ?? new DateTime();
        $birth = clone $dob;

        $span = $birth->diff($today);

        return ($span->y * 12) + $span->m;
    }

    public function processDays(DateTime $dob, ?DateTime $asOf = null): int
    {
        $today = $asOf ?? new DateTime();
        $birth = clone $dob;

        $span = $birth->diff($today);

        return $span->days;
    }

    public function detailedAge(DateTime $dob, ?DateTime $asOf = null): array
    {
        $today = $asOf ?? new DateTime();
        $birth = clone $dob;

        $span = $birth->diff($today);

        return [
            'years' => $span->y,
            'months' => $span->m,
            'days' => $span->d,
            'total_days' => $span->days,
            'total_months' => ($span->y * 12) + $span->m,
        ];
    }

    public function checkAdult(DateTime $dob, int $threshold = 18, ?DateTime $asOf = null): bool
    {
        return $this->process($dob, $asOf) >= $threshold;
    }

    public function checkMinor(DateTime $dob, int $majority = 18, ?DateTime $asOf = null): bool
    {
        return !$this->checkAdult($dob, $majority, $asOf);
    }

    public function checkSenior(DateTime $dob, int $seniorThreshold = 65, ?DateTime $asOf = null): bool
    {
        return $this->process($dob, $asOf) >= $seniorThreshold;
    }

    public function checkTeen(DateTime $dob, ?DateTime $asOf = null): bool
    {
        $age = $this->process($dob, $asOf);

        return $age >= 13 && $age <= 19;
    }

    public function ageCategory(DateTime $dob, ?DateTime $asOf = null): string
    {
        $age = $this->process($dob, $asOf);

        if ($age < 0) {
            return 'future';
        }

        if ($age < 1) {
            return 'newborn';
        }

        if ($age < 2) {
            return 'infant';
        }

        if ($age < 5) {
            return 'toddler';
        }

        if ($age < 13) {
            return 'child';
        }

        if ($age < 20) {
            return 'teen';
        }

        if ($age < 40) {
            return 'young_adult';
        }

        if ($age < 60) {
            return 'middle_aged';
        }

        return 'senior_citizen';
    }

    public function reverseEngineerBirthdate(int $age, ?DateTime $asOf = null): DateTime
    {
        if ($age < self::YOUNGEST_VALID || $age > self::OLDEST_VALID) {
            throw new DobException("Age must be between " . self::YOUNGEST_VALID . " and " . self::OLDEST_VALID);
        }

        $today = $asOf ?? new DateTime();

        return clone $today->modify("-{$age} years");
    }

    public function upcomingBirthday(DateTime $dob, ?DateTime $asOf = null): DateTime
    {
        $today = $asOf ?? new DateTime();
        $today->setTime(0, 0, 0);

        $thisYear = (int) $today->format('Y');
        $month = (int) $dob->format('m');
        $day = (int) $dob->format('d');

        $birthday = DateTime::createFromFormat(
            'Y-m-d',
            "{$thisYear}-{$month}-{$day}"
        );

        if ($birthday === false) {
            throw new DobException('Invalid date of birth provided');
        }

        $birthday->setTime(0, 0, 0);

        if ($birthday <= $today) {
            $birthday->modify('+1 year');
        }

        return $birthday;
    }

    public function daysToBirthday(DateTime $dob, ?DateTime $asOf = null): int
    {
        $next = $this->upcomingBirthday($dob, $asOf);
        $today = $asOf ?? new DateTime();

        return $today->diff($next)->days;
    }

    public function verifyDateOfBirth(DateTime $dob, ?DateTime $asOf = null): void
    {
        $today = $asOf ?? new DateTime();
        $today->setTime(0, 0, 0);

        $birth = clone $dob;
        $birth->setTime(0, 0, 0);

        if ($birth > $today) {
            throw new DobException('Date of birth is in the future');
        }

        $age = $this->process($dob, $asOf);

        if ($age < self::YOUNGEST_VALID || $age > self::OLDEST_VALID) {
            throw new DobException('Date of birth produces unrealistic age');
        }
    }
}
