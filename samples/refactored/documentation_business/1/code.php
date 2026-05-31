<?php
declare(strict_types=1);

namespace Billing\Core\Rules;

use Doctrine\ORM\EntityManager;
use Symfony\Component\Validator\Mapping\ClassMetadata;

/**
 * Centralized business rules for subscription billing.
 *
 * All billing-related business rules are defined here to ensure
 * single source of truth. Rules are documented with:
 * - The rule itself
 * - Trigger conditions
 * - Expected behavior
 * - Related rules
 */
final class BillingRules
{
    public const RETRY_DAYS = [1, 3, 7];
    public const SUSPENSION_GRACE_DAYS = 14;
    public const ANNUAL_DISCOUNT = 0.15;
    public const REFERRAL_CREDIT_AMOUNT = 10.00;
    public const REFERRAL_CREDIT_EXPIRY_DAYS = 90;
    public const MIN_REFUND_THRESHOLD = 1.00;

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        // Validation rules for billing amounts
    }

    public static function calculateProratedRefund(
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        float $amountPaid
    ): float {
        $totalDays = $periodStart->diff($periodEnd)->days;
        $daysRemaining = (new \DateTimeImmutable())->diff($periodEnd)->days;

        if ($daysRemaining <= 0) {
            return 0.0;
        }

        return round(($daysRemaining / $totalDays) * $amountPaid, 2);
    }

    public static function getRetryDate(\DateTimeImmutable $billingDate, int $retryNumber): \DateTimeImmutable
    {
        $days = self::RETRY_DAYS[$retryNumber - 1] ?? throw new \InvalidArgumentException(
            "Invalid retry number: {$retryNumber}"
        );
        return $billingDate->modify("+{$days} days");
    }

    public static function calculateUnusedCredit(
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        float $billingAmount
    ): float {
        $totalDays = $periodStart->diff($periodEnd)->days;
        $daysUsed = $periodStart->diff(new \DateTimeImmutable())->days;
        $daysRemaining = max(0, $totalDays - $daysUsed);

        $dailyRate = $billingAmount / $totalDays;
        return round($dailyRate * $daysRemaining, 2);
    }
}
