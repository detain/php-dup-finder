<?php
declare(strict_types=1);

namespace DeliveryPro\Tracking\Shipment;

use Psr\Log\LoggerInterface;
use DeliveryPro\Tracking\Entities\Package;
use DeliveryPro\Tracking\Repository\ShipmentRepository;

final class DeliveryAttemptHandler
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
        private readonly ShipmentRepository $shipmentRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function handleDeliveryAttempt(string $trackingNumber, int $attemptNumber): DeliveryResult
    {
        $shipment = $this->shipmentRepository->findByTrackingNumber($trackingNumber);
        if ($shipment === null) {
            throw new \RuntimeException("Shipment not found: {$trackingNumber}");
        }

        if ($attemptNumber > self::MAX_DELIVERY_ATTEMPTS) {
            $this->logger->warning('Maximum delivery attempts reached', [
                'tracking_number' => $trackingNumber,
                'attempt' => $attemptNumber,
                'max_attempts' => self::MAX_DELIVERY_ATTEMPTS,
            ]);
            return $this->initiateReturnProcess($shipment);
        }

        $timeoutHours = $this->getAttemptTimeoutHours($attemptNumber);
        $lastAttempt = $shipment->getLastDeliveryAttempt();
        if ($lastAttempt !== null) {
            $hoursSinceLastAttempt = (time() - $lastAttempt->getTimestamp()) / 3600;
            if ($hoursSinceLastAttempt < $timeoutHours) {
                $this->logger->info('Too soon for retry', [
                    'tracking_number' => $trackingNumber,
                    'hours_since_last_attempt' => $hoursSinceLastAttempt,
                    'required_hours' => $timeoutHours,
                ]);
                return DeliveryResult::retryScheduled($timeoutHours - (int)$hoursSinceLastAttempt);
            }
        }

        $this->logger->info('Processing delivery attempt', [
            'tracking_number' => $trackingNumber,
            'attempt' => $attemptNumber,
        ]);

        return $this->processDelivery($shipment, $attemptNumber);
    }

    private function getAttemptTimeoutHours(int $attemptNumber): int
    {
        return match ($attemptNumber) {
            1 => self::FIRST_ATTEMPT_TIMEOUT_HOURS,
            2 => self::SECOND_ATTEMPT_TIMEOUT_HOURS,
            default => self::THIRD_ATTEMPT_TIMEOUT_HOURS,
        };
    }

    private function processDelivery(Package $shipment, int $attemptNumber): DeliveryResult
    {
        $requiresSignature = $shipment->getDeclaredValue() >= self::SIGNATURE_REQUIRED_THRESHOLD_VALUE;
        $hasInsurance = $shipment->getDeclaredValue() >= self::INSURANCE_COVERAGE_MINIMUM_VALUE
            && $shipment->getDeclaredValue() <= self::INSURANCE_COVERAGE_MAXIMUM_VALUE;

        if ($shipment->isOverweight(self::PACKAGE_WEIGHT_LIMIT_KG)) {
            return DeliveryResult::failed('Package exceeds weight limit');
        }

        if ($shipment->exceedsDimensions(self::PACKAGE_DIMENSION_LIMIT_CM)) {
            return DeliveryResult::failed('Package exceeds dimension limits');
        }

        if ($shipment->isDelivered()) {
            return DeliveryResult::success();
        }

        if ($shipment->requiresSignature() && !$requiresSignature) {
            $this->logger->info('Signature requirement upgraded', [
                'tracking_number' => $shipment->getTrackingNumber(),
            ]);
        }

        return DeliveryResult::attempted($attemptNumber + 1);
    }

    private function initiateReturnProcess(Package $shipment): DeliveryResult
    {
        $daysInTransit = (time() - $shipment->getShippedAt()) / 86400;
        if ($daysInTransit > self::AUTO_RETURN_THRESHOLD_DAYS) {
            $this->logger->info('Initiating automatic return to sender', [
                'tracking_number' => $shipment->getTrackingNumber(),
                'days_in_transit' => $daysInTransit,
            ]);
            $shipment->markForReturn();
            return DeliveryResult::returnInitiated();
        }

        return DeliveryResult::heldForPickup();
    }
}
