<?php
declare(strict_types=1);

namespace Workday\HR\Service;

use Workday\HR\Repository\EmployeeRepository;
use Workday\HR\Repository\PayrollRepository;
use Workday\HR\Repository\BenefitsRepository;
use Workday\HR\Entity\Employee;
use Workday\HR\Entity\PayrollRun;
use Workday\HR\Entity\Deduction;
use Workday\HR\Entity\EmployeeBenefit;
use Workday\HR\Exception\HRException;
use Workday\HR\Service\Benefits\EnrollmentService;
use Workday\HR\Service\Compensation\BonusCalculator;
use Psr\Log\LoggerInterface;

final class EmployeeOnboardingService
{
    private EmployeeRepository $employeeRepo;
    private PayrollRepository $payrollRepo;
    private BenefitsRepository $benefitsRepo;
    private EnrollmentService $enrollmentService;
    private BonusCalculator $bonusCalculator;
    private LoggerInterface $logger;

    public function __construct(
        EmployeeRepository $employeeRepo,
        PayrollRepository $payrollRepo,
        BenefitsRepository $benefitsRepo,
        EnrollmentService $enrollmentService,
        BonusCalculator $bonusCalculator,
        LoggerInterface $logger
    ) {
        $this->employeeRepo = $employeeRepo;
        $this->payrollRepo = $payrollRepo;
        $this->benefitsRepo = $benefitsRepo;
        $this->enrollmentService = $enrollmentService;
        $this->bonusCalculator = $bonusCalculator;
        $this->logger = $logger;
    }

    public function onboardEmployee(array $employeeData): OnboardingResult
    {
        $this->logger->info('Starting employee onboarding', [
            'email' => $employeeData['email'] ?? 'unknown',
            'department' => $employeeData['department'] ?? 'unknown'
        ]);

        $onboardingLock = $this->employeeRepo->acquireOnboardingLock($employeeData['email']);
        if ($onboardingLock === null) {
            throw new HRException("Another onboarding process is already in progress for: {$employeeData['email']}");
        }

        $this->logger->debug('Onboarding lock acquired', ['email' => $employeeData['email']]);

        try {
            $employee = Employee::create([
                'email' => $employeeData['email'],
                'first_name' => $employeeData['first_name'],
                'last_name' => $employeeData['last_name'],
                'department' => $employeeData['department'],
                'job_title' => $employeeData['job_title'],
                'manager_id' => $employeeData['manager_id'] ?? null,
                'start_date' => new \DateTimeImmutable($employeeData['start_date']),
                'employment_type' => $employeeData['employment_type'] ?? 'full_time',
                'status' => 'onboarding',
                'created_at' => new \DateTimeImmutable()
            ]);

            $savedEmployee = $this->employeeRepo->save($employee);
            $this->logger->debug('Employee record created', ['employee_id' => $savedEmployee->getId()]);

            $this->employeeRepo->createWorkSchedule(
                $savedEmployee->getId(),
                $employeeData['work_schedule'] ?? ['hours_per_week' => 40]
            );

            $this->employeeRepo->createEmploymentHistory(
                $savedEmployee->getId(),
                'onboarding_started',
                'Employee onboarding initiated'
            );

            $payrollProfile = $this->payrollRepo->createPayrollProfile(
                $savedEmployee->getId(),
                [
                    'pay_frequency' => 'monthly',
                    'currency' => $employeeData['currency'] ?? 'USD',
                    'compensation_type' => $employeeData['compensation_type'] ?? 'salary',
                    'annual_salary' => $employeeData['annual_salary'] ?? 0,
                    'effective_date' => $employeeData['start_date']
                ]
            );

            $this->logger->debug('Payroll profile created', [
                'employee_id' => $savedEmployee->getId(),
                'profile_id' => $payrollProfile->getId()
            ]);

            $enrollmentResult = $this->enrollmentService->initiateBenefitsEnrollment(
                $savedEmployee->getId(),
                $employeeData['benefits_eligible'] ?? true
            );

            if ($employeeData['signing_bonus'] ?? false) {
                $signingBonus = $this->bonusCalculator->calculateSigningBonus(
                    $savedEmployee->getId(),
                    $employeeData['annual_salary']
                );

                $bonusPayment = $this->payrollRepo->createOneTimePayment(
                    $savedEmployee->getId(),
                    $signingBonus,
                    'signing_bonus',
                    'Bonus paid as part of new hire package'
                );

                $this->payrollRepo->schedulePayment($bonusPayment);
            }

            $this->employeeRepo->updateOnboardingProgress(
                $savedEmployee->getId(),
                'payroll_configured'
            );

            $this->employeeRepo->releaseOnboardingLock($onboardingLock);

            $this->logger->info('Employee onboarding completed', [
                'employee_id' => $savedEmployee->getId(),
                'email' => $savedEmployee->getEmail(),
                'benefits_enrollment_initiated' => $enrollmentResult['initiated']
            ]);

            return new OnboardingResult([
                'success' => true,
                'employee_id' => $savedEmployee->getId(),
                'payroll_profile_id' => $payrollProfile->getId(),
                'benefits_enrollment_id' => $enrollmentResult['enrollment_id'] ?? null,
                'onboarding_steps' => $this->getRemainingOnboardingSteps($savedEmployee)
            ]);

        } catch (\Throwable $e) {
            $this->employeeRepo->releaseOnboardingLock($onboardingLock);
            $this->logger->error('Employee onboarding failed', [
                'email' => $employeeData['email'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function completeOnboarding(string $employeeId): CompletionResult
    {
        $employee = $this->employeeRepo->findById($employeeId);
        if ($employee === null) {
            throw new HRException("Employee not found: {$employeeId}");
        }

        if ($employee->getStatus() !== 'onboarding') {
            throw new HRException("Employee is not in onboarding status");
        }

        $pendingBenefits = $this->benefitsRepo->getPendingEnrollments($employeeId);
        if (count($pendingBenefits) > 0) {
            throw new HRException("Cannot complete onboarding with pending benefit enrollments");
        }

        $this->employeeRepo->updateStatus($employeeId, 'active');
        $this->employeeRepo->createEmploymentHistory(
            $employeeId,
            'onboarding_completed',
            'Employee onboarding process completed'
        );

        $this->logger->info('Employee onboarding completed', [
            'employee_id' => $employeeId
        ]);

        return new CompletionResult([
            'success' => true,
            'employee_id' => $employeeId,
            'completed_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }

    private function getRemainingOnboardingSteps(Employee $employee): array
    {
        return [
            ['step' => 'complete_i9', 'required' => true],
            ['step' => 'setup_direct_deposit', 'required' => true],
            ['step' => 'benefit_enrollment', 'required' => $employee->isBenefitsEligible()],
            ['step' => 'emergency_contact', 'required' => true],
            ['step' => 'company_policy_acknowledgment', 'required' => true],
        ];
    }
}
