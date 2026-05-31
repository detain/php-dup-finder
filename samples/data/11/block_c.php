<?php
declare(strict_types=1);

namespace LogisticsPro\Shipment\Freight;

use Psr\Log\LoggerInterface;
use LogisticsPro\Shipment\Entities\Shipment;
use LogisticsPro\Shipment\Repository\ZoneRepository;

final class UsFreightSurchargeCalculator
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

    private const HAZMAT_ADDITIONAL_SURCHARGE = 0.025;

    public function __construct(
        private readonly ZoneRepository $zoneRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateFuelSurcharge(Shipment $shipment, string $stateCode, bool $isHazardous): array
    {
        $stateCode = strtoupper(trim($stateCode));

        if ($this->isExemptState($stateCode)) {
            $this->logger->info('No fuel surcharge for hazmat in exempt state', [
                'state' => $stateCode,
                'shipment_id' => $shipment->getId(),
            ]);
            return $this->buildResult($shipment, $stateCode, 0.0);
        }

        $baseRate = $this->getStateTaxRate($stateCode);
        if ($baseRate === null) {
            $this->logger->warning('Unknown state for fuel surcharge, using default', [
                'state' => $stateCode,
                'default_rate' => 0.05,
            ]);
            $baseRate = 0.05;
        }

        $finalRate = $isHazardous ? $baseRate + self::HAZMAT_ADDITIONAL_SURCHARGE : $baseRate;
        $surchargeAmount = round($shipment->getBaseFreightCost() * $finalRate, 2);

        $this->logger->debug('Fuel surcharge calculated', [
            'shipment_id' => $shipment->getId(),
            'state' => $stateCode,
            'base_rate' => $baseRate,
            'final_rate' => $finalRate,
            'surcharge_amount' => $surchargeAmount,
        ]);

        return $this->buildResult($shipment, $stateCode, $surchargeAmount, $finalRate);
    }

    private function isExemptState(string $stateCode): bool
    {
        return isset(self::CATEGORY_EXEMPT_STATES[$stateCode]);
    }

    private function getStateTaxRate(string $stateCode): ?float
    {
        return self::STATE_TAX_RATES[$stateCode] ?? null;
    }

    private function buildResult(Shipment $shipment, string $state, float $surchargeAmount, ?float $rate = null): array
    {
        return [
            'shipment_id' => $shipment->getId(),
            'tracking_number' => $shipment->getTrackingNumber(),
            'state' => $state,
            'base_freight' => $shipment->getBaseFreightCost(),
            'fuel_surcharge_rate' => $rate ?? 0.0,
            'fuel_surcharge_amount' => $surchargeAmount,
            'total_freight' => $shipment->getBaseFreightCost() + $surchargeAmount,
            'calculated_at' => date('c'),
        ];
    }
}
