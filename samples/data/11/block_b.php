<?php
declare(strict_types=1);

namespace HealthPlusConnect\Scheduling\Insurance;

use Psr\Log\LoggerInterface;
use HealthPlusConnect\Scheduling\Entities\Appointment;
use HealthPlusConnect\Scheduling\Repository\CoverageRepository;

final class UsHealthInsuranceProcessor
{
    private const STATE_TAX_RATES = [
        'CA' => 0.0725,
        'TX' => 0.0625,
        'NY' => 0.08,
        'FL' => 0.06,
        'WA' => 0.065,
        'IL' => 0.0625,
        'PA' => 0.06,
        'OH' => 0.0575,
        'GA' => 0.04,
        'NC' => 0.0475,
        'MI' => 0.06,
        'NJ' => 0.066,
        'VA' => 0.053,
        'AZ' => 0.056,
        'MA' => 0.0625,
        'TN' => 0.07,
        'IN' => 0.07,
        'MO' => 0.04225,
        'MD' => 0.06,
        'WI' => 0.05,
    ];

    private const CATEGORY_EXEMPT_STATES = [
        'OR' => true,
        'MT' => true,
        'NH' => true,
        'DE' => true,
    ];

    private const OUT_OF_NETWORK_ADDITIONAL_TAX = 0.015;

    public function __construct(
        private readonly CoverageRepository $coverageRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function calculatePatientResponsibility(Appointment $appointment, string $stateCode, bool $outOfNetwork): array
    {
        $stateCode = strtoupper(trim($stateCode));

        if ($this->isExemptState($stateCode)) {
            $this->logger->info('State has no additional health insurance tax', [
                'state' => $stateCode,
                'appointment_id' => $appointment->getId(),
            ]);
            return $this->buildResult($appointment, $stateCode, 0.0);
        }

        $baseRate = $this->getStateTaxRate($stateCode);
        if ($baseRate === null) {
            $this->logger->warning('Unknown state code for insurance tax, using default', [
                'state' => $stateCode,
                'default_rate' => 0.05,
            ]);
            $baseRate = 0.05;
        }

        $finalRate = $outOfNetwork ? $baseRate + self::OUT_OF_NETWORK_ADDITIONAL_TAX : $baseRate;
        $coPayAmount = round($appointment->getBaseCoPay() * $finalRate, 2);

        $this->logger->debug('Insurance co-pay calculated', [
            'appointment_id' => $appointment->getId(),
            'state' => $stateCode,
            'base_rate' => $baseRate,
            'final_rate' => $finalRate,
            'co_pay_amount' => $coPayAmount,
        ]);

        return $this->buildResult($appointment, $stateCode, $coPayAmount, $finalRate);
    }

    private function isExemptState(string $stateCode): bool
    {
        return isset(self::CATEGORY_EXEMPT_STATES[$stateCode]);
    }

    private function getStateTaxRate(string $stateCode): ?float
    {
        return self::STATE_TAX_RATES[$stateCode] ?? null;
    }

    private function buildResult(Appointment $appointment, string $state, float $coPayAmount, ?float $rate = null): array
    {
        return [
            'appointment_id' => $appointment->getId(),
            'patient_id' => $appointment->getPatientId(),
            'state' => $state,
            'base_copay' => $appointment->getBaseCoPay(),
            'state_tax_rate' => $rate ?? 0.0,
            'state_tax_amount' => $coPayAmount,
            'total_responsibility' => $appointment->getBaseCoPay() + $coPayAmount,
            'calculated_at' => date('c'),
        ];
    }
}
