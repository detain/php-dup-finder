<?php

declare(strict_types=1);

namespace App\HR;

use App\Entity\Employee;
use App\Repository\EmployeeRepository;
use Psr\Log\LoggerInterface;

final class EmployeeAgeService
{
    public function __construct(
        private readonly EmployeeRepository $employeeRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Calculates employee age in years from date of birth.
     *
     * Uses PHP's DateTime and DateInterval for precise age calculation.
     * Handles leap years and varying month lengths correctly.
     */
    public function calculateAge(int $employeeId): ?int
    {
        $employee = $this->employeeRepository->findById($employeeId);

        if ($employee === null) {
            $this->logger->error('Employee not found for age calculation', [
                'employee_id' => $employeeId,
            ]);
            return null;
        }

        $dateOfBirth = $employee->getDateOfBirth();

        if ($dateOfBirth === null) {
            $this->logger->warning('Employee has no date of birth on record', [
                'employee_id' => $employeeId,
            ]);
            return null;
        }

        $today = new \DateTimeImmutable();
        $ageInterval = $dateOfBirth->diff($today);
        $age = $ageInterval->y;

        $this->logger->debug('Employee age calculated', [
            'employee_id' => $employeeId,
            'date_of_birth' => $dateOfBirth->format('Y-m-d'),
            'age' => $age,
        ]);

        return $age;
    }

    /**
     * Determines if employee is considered a senior employee (age >= 65).
     */
    public function isSenior(int $employeeId): bool
    {
        $age = $this->calculateAge($employeeId);
        return $age !== null && $age >= 65;
    }

    /**
     * Calculates the year in which employee will reach retirement age.
     */
    public function getRetirementYear(int $employeeId, int $retirementAge = 65): ?int
    {
        $age = $this->calculateAge($employeeId);

        if ($age === null) {
            return null;
        }

        $yearsUntilRetirement = $retirementAge - $age;
        $currentYear = (int) date('Y');

        return $currentYear + $yearsUntilRetirement;
    }
}
