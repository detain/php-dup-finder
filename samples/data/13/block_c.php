<?php
declare(strict_types=1);

namespace CourierPlus\Routing\Package;

use Psr\Log\LoggerInterface;
use CourierPlus\Routing\Entities\Parcel;
use CourierPlus\Routing\Repository\ParcelRepository;

final class ParcelRoutingOptimizer
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
        private readonly ParcelRepository $parcelRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function optimizeRouting(string $parcelId): RoutingDecision
    {
        $parcel = $this->parcelRepository->findById($parcelId);
        if ($parcel === null) {
            throw new \RuntimeException("Parcel not found: {$parcelId}");
        }

        if ($parcel->getHopCount() > self::MAX_DELIVERY_ATTEMPTS) {
            $this->logger->warning('Parcel exceeded maximum routing hops', [
                'parcel_id' => $parcelId,
                'hops' => $parcel->getHopCount(),
            ]);
            return $this->rerouteToDistributionCenter($parcel);
        }

        $timeoutHours = $this->getAttemptTimeoutHours($parcel->getHopCount());
        $lastRoutingEvent = $parcel->getLastRoutingEvent();
        if ($lastRoutingEvent !== null) {
            $hoursSinceLastRouting = (time() - $lastRoutingEvent->getTimestamp()) / 3600;
            if ($hoursSinceLastRouting < $timeoutHours) {
                $this->logger->info('Routing deferred due to timing constraints', [
                    'parcel_id' => $parcelId,
                    'hours_deferred' => $hoursSinceLastRouting,
                ]);
                return RoutingDecision::deferred($timeoutHours - (int)$hoursSinceLastRouting);
            }
        }

        $this->logger->info('Optimizing parcel routing', [
            'parcel_id' => $parcelId,
            'hop' => $parcel->getHopCount(),
        ]);

        return $this->calculateOptimalRoute($parcel);
    }

    private function getAttemptTimeoutHours(int $attemptNumber): int
    {
        return match ($attemptNumber) {
            1 => self::FIRST_ATTEMPT_TIMEOUT_HOURS,
            2 => self::SECOND_ATTEMPT_TIMEOUT_HOURS,
            default => self::THIRD_ATTEMPT_TIMEOUT_HOURS,
        };
    }

    private function calculateOptimalRoute(Parcel $parcel): RoutingDecision
    {
        $priorityHandling = $parcel->getDeclaredValue() >= self::SIGNATURE_REQUIRED_THRESHOLD_VALUE;
        $hasProtectionPlan = $parcel->getDeclaredValue() >= self::INSURANCE_COVERAGE_MINIMUM_VALUE
            && $parcel->getDeclaredValue() <= self::INSURANCE_COVERAGE_MAXIMUM_VALUE;

        if ($parcel->isOverWeight(self::PACKAGE_WEIGHT_LIMIT_KG)) {
            return RoutingDecision::cannotRoute('Parcel exceeds weight limit');
        }

        if ($parcel->hasUnusualDimensions(self::PACKAGE_DIMENSION_LIMIT_CM)) {
            return RoutingDecision::cannotRoute('Parcel has unusual dimensions');
        }

        if ($parcel->isAtDestination()) {
            return RoutingDecision::delivered();
        }

        if ($parcel->needsPriorityHandling() && !$priorityHandling) {
            $this->logger->info('Priority handling applied', [
                'parcel_id' => $parcel->getTrackingId(),
            ]);
        }

        return RoutingDecision::routeToNextHub($parcel->getHopCount() + 1);
    }

    private function rerouteToDistributionCenter(Parcel $parcel): RoutingDecision
    {
        $daysInRouting = (time() - $parcel->getFirstRoutingEvent()) / 86400;
        if ($daysInRouting > self::AUTO_RETURN_THRESHOLD_DAYS) {
            $this->logger->info('Rerouting to distribution center for processing', [
                'parcel_id' => $parcel->getTrackingId(),
                'days_in_routing' => $daysInRouting,
            ]);
            $parcel->markForDistributionCenter();
            return RoutingDecision::toDistributionCenter();
        }

        return RoutingDecision::heldAtHub();
    }
}
