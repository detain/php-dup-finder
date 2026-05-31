<?php

declare(strict_types=1);

namespace App\HR;

use App\Entity\Employee;
use App\Repository\EmployeeRepository;
use Psr\Log\LoggerInterface;

final class EmployeeTenureService
{
    public function __construct(
        private readonly EmployeeRepository $employeeRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Calculates employee tenure in years from hire date.
     *
     * Uses direct timestamp calculation and integer division.
     * Provides the same result as DateTime diff but through arithmetic.
     */
    public function calculateTenure(int $employeeId): ?int
    {
        $employee = $this->employeeRepository->findById($employeeId);

        if ($employee === null) {
            $this->logger->error('Employee not found for tenure calculation', [
                'employee_id' => $employeeId,
            ]);
            return null;
        }

        $hireDate = $employee->getHireDate();

        if ($hireDate === null) {
            $this->logger->warning('Employee has no hire date on record', [
                'employee_id' => $employeeId,
            ]);
            return null;
        }

        $now = time();
        $hireTimestamp = $hireDate->getTimestamp();
        $secondsElapsed = $now - $hireTimestamp;
        $daysElapsed = $secondsElapsed / (60 * 60 * 24);
        $yearsElapsed = (int) ($daysElapsed / 365.25);

        $this->logger->debug('Employee tenure calculated', [
            'employee_id' => $employeeId,
            'hire_date' => $hireDate->format('Y-m-d'),
            'tenure_years' => $yearsElapsed,
        ]);

        return $yearsElapsed;
    }

    /**
     * Determines if employee is considered veteran (tenure >= 10 years).
     */
    public function isVeteran(int $employeeId): bool
    {
        $tenure = $this->calculateTenure($employeeId);
        return $tenure !== null && $tenure >= 10;
    }

    /**
     * Calculates the year in which employee will reach tenure milestone.
     */
    public function getTenureMilestoneYear(int $employeeId, int $milestoneYears = 10): ?int
    {
        $employee = $this->employeeRepository->findById($employeeId);
        if ($employee === null || $employee->getHireDate() === null) {
            return null;
        }

        $currentTenure = $this->calculateTenure($employeeId);
        if ($currentTenure === null) {
            return null;
        }

        $yearsUntilMilestone = $milestoneYears - $currentTenure;
        $currentYear = (int) date('Y');

        return $currentYear + max(0, $yearsUntilMilestone);
    }
}
