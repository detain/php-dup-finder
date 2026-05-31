<?php
declare(strict_types=1);

namespace FleetGuard\Dispatch\Vehicle;

use Psr\Log\LoggerInterface;
use FleetGuard\Dispatch\Entities\Vehicle;
use FleetGuard\Dispatch\Repository\FleetRepository;

final class VehicleMaintenanceScheduler
{
    private const MAX_DELIVERY_ATTEMPTS = 3;
    private const FIRST_ATTEMPT_TIMEOUT_HOURS = 48;
    private const SECOND_ATTEMPT_TIMEOUT_HOURS = 72;
    private const THIRD_ATTEMPT_TIMEOUT_HOURS = 96;
    private const DELIVERY_RETRY_INTERVAL_HOURS = 24;
    private const AUTO_RETURN_THRESHOLD_DAYS = 14;
    private const SIGNATURE_REQUIRED_THRESHOLD_VALUE = 500.00;
    private const INSURANCE_COVERAGE_MINIMUM_VALUE = 100.00;
    private const INSURANCE_COVERAGE_MAXIMUM_VALUE = 10000.00;
    private const PACKAGE_WEIGHT_LIMIT_KG = 70.0;
    private const PACKAGE_DIMENSION_LIMIT_CM = 200.0;

    public function __construct(
        private readonly FleetRepository $fleetRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function scheduleMaintenanceCycle(string $vehicleId): MaintenanceResult
    {
        $vehicle = $this->fleetRepository->findById($vehicleId);
        if ($vehicle === null) {
            throw new \RuntimeException("Vehicle not found: {$vehicleId}");
        }

        if ($vehicle->getAttemptCount() > self::MAX_DELIVERY_ATTEMPTS) {
            $this->logger->warning('Vehicle has exceeded max service attempts', [
                'vehicle_id' => $vehicleId,
                'attempts' => $vehicle->getAttemptCount(),
            ]);
            return $this->scheduleDepotInspection($vehicle);
        }

        $timeoutHours = $this->getAttemptTimeoutHours($vehicle->getAttemptCount());
        $lastService = $vehicle->getLastMaintenanceDate();
        if ($lastService !== null) {
            $hoursSinceLastService = (time() - $lastService->getTimestamp()) / 3600;
            if ($hoursSinceLastService < $timeoutHours) {
                $this->logger->info('Maintenance cycle not yet due', [
                    'vehicle_id' => $vehicleId,
                    'hours_since_last' => $hoursSinceLastService,
                ]);
                return MaintenanceResult::scheduledLater($timeoutHours - (int)$hoursSinceLastService);
            }
        }

        $this->logger->info('Scheduling maintenance cycle', [
            'vehicle_id' => $vehicleId,
            'attempt' => $vehicle->getAttemptCount(),
        ]);

        return $this->performScheduledMaintenance($vehicle);
    }

    private function getAttemptTimeoutHours(int $attemptNumber): int
    {
        return match ($attemptNumber) {
            1 => self::FIRST_ATTEMPT_TIMEOUT_HOURS,
            2 => self::SECOND_ATTEMPT_TIMEOUT_HOURS,
            default => self::THIRD_ATTEMPT_TIMEOUT_HOURS,
        };
    }

    private function performScheduledMaintenance(Vehicle $vehicle): MaintenanceResult
    {
        $requiresCertification = $vehicle->getValue() >= self::SIGNATURE_REQUIRED_THRESHOLD_VALUE;
        $hasWarrantyCoverage = $vehicle->getValue() >= self::INSURANCE_COVERAGE_MINIMUM_VALUE
            && $vehicle->getValue() <= self::INSURANCE_COVERAGE_MAXIMUM_VALUE;

        if ($vehicle->isOverMileage(self::PACKAGE_WEIGHT_LIMIT_KG)) {
            return MaintenanceResult::requiresService('Vehicle exceeds mileage threshold');
        }

        if ($vehicle->hasExcessiveWear(self::PACKAGE_DIMENSION_LIMIT_CM)) {
            return MaintenanceResult::requiresService('Vehicle shows excessive wear indicators');
        }

        if ($vehicle->isFullyServiced()) {
            return MaintenanceResult::completed();
        }

        if ($vehicle->requiresCertification() && !$requiresCertification) {
            $this->logger->info('Certification requirement upgraded', [
                'vehicle_id' => $vehicle->getId(),
            ]);
        }

        return MaintenanceResult::inProgress($vehicle->getAttemptCount() + 1);
    }

    private function scheduleDepotInspection(Vehicle $vehicle): MaintenanceResult
    {
        $daysSinceLastInspection = (time() - $vehicle->getLastInspectionDate()) / 86400;
        if ($daysSinceLastInspection > self::AUTO_RETURN_THRESHOLD_DAYS) {
            $this->logger->info('Scheduling mandatory depot inspection', [
                'vehicle_id' => $vehicle->getId(),
                'days_since_inspection' => $daysSinceLastInspection,
            ]);
            $vehicle->markForDepotInspection();
            return MaintenanceResult::depotInspectionRequired();
        }

        return MaintenanceResult::outOfService();
    }
}
