<?php
declare(strict_types=1);

namespace Routing\Shared;

final class RoutingConstants
{
    public const MAX_ATTEMPTS = 3;
    public const FIRST_ATTEMPT_TIMEOUT_HOURS = 48;
    public const SECOND_ATTEMPT_TIMEOUT_HOURS = 72;
    public const THIRD_ATTEMPT_TIMEOUT_HOURS = 96;
    public const RETRY_INTERVAL_HOURS = 24;
    public const AUTO_RETURN_THRESHOLD_DAYS = 14;
    public const SIGNATURE_THRESHOLD_VALUE = 500.00;
    public const INSURANCE_MIN_VALUE = 100.00;
    public const INSURANCE_MAX_VALUE = 10000.00;
    public const WEIGHT_LIMIT_KG = 70.0;
    public const DIMENSION_LIMIT_CM = 200.0;

    public static function getTimeoutForAttempt(int $attemptNumber): int
    {
        return match ($attemptNumber) {
            1 => self::FIRST_ATTEMPT_TIMEOUT_HOURS,
            2 => self::SECOND_ATTEMPT_TIMEOUT_HOURS,
            default => self::THIRD_ATTEMPT_TIMEOUT_HOURS,
        };
    }

    public static function isSignatureRequired(float $declaredValue): bool
    {
        return $declaredValue >= self::SIGNATURE_THRESHOLD_VALUE;
    }

    public static function hasInsuranceCoverage(float $declaredValue): bool
    {
        return $declaredValue >= self::INSURANCE_MIN_VALUE && $declaredValue <= self::INSURANCE_MAX_VALUE;
    }
}

interface RoutingProcessorInterface
{
    public function process(string $entityId): RoutingResult;
    public function shouldReroute(RoutingEntity $entity): bool;
}

trait RoutingProcessorLogic
{
    private RoutingConstants $constants;

    protected function handleRoutingAttempt(RoutingEntity $entity): RoutingResult
    {
        $attempts = $entity->getAttemptCount();

        if ($attempts > $this->constants::MAX_ATTEMPTS) {
            return $this->initiateReroute($entity);
        }

        $timeout = $this->constants::getTimeoutForAttempt($attempts);
        $lastAttempt = $entity->getLastAttemptTime();

        if ($lastAttempt !== null) {
            $hoursElapsed = (time() - $lastAttempt) / 3600;
            if ($hoursElapsed < $timeout) {
                return RoutingResult::retryLater($timeout - (int)$hoursElapsed);
            }
        }

        return $this->performRouting($entity);
    }

    protected function validatePackage(ValidationTarget $target): bool
    {
        if ($target->isOverWeight($this->constants::WEIGHT_LIMIT_KG)) {
            return false;
        }

        if ($target->exceedsDimensions($this->constants::DIMENSION_LIMIT_CM)) {
            return false;
        }

        return true;
    }

    protected function shouldRequireSignature(Parcel $parcel): bool
    {
        return $this->constants::isSignatureRequired($parcel->getDeclaredValue());
    }
}
