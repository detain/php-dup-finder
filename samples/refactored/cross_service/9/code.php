<?php
declare(strict_types=1);

namespace Acme\Common\Fraud;

/**
 * acme/fraud-policy is the single source of truth for fraud scoring.
 * OrderService, PaymentService, and RiskService each translate their local
 * signal feed into FraudSignals and call score() so dashboards and live blocks
 * cannot drift.
 */
final class FraudScorer
{
    public const DECLINE_THRESHOLD = 70;
    public const REVIEW_THRESHOLD  = 40;
    public const MAX_SCORE         = 100;

    public function score(FraudSignals $s): int
    {
        $score = 0;

        $score += match (true) {
            $s->ordersLastHour > 5 => 30,
            $s->ordersLastHour > 2 => 15,
            default                 => 0,
        };

        if ($s->billingCountry !== $s->shippingCountry) { $score += 20; }
        if ($s->ipCountry !== $s->billingCountry)       { $score += 15; }
        if (in_array($s->binBucket, ['prepaid', 'gift'], true)) { $score += 25; }

        $score += match (true) {
            $s->accountAgeDays < 1 => 20,
            $s->accountAgeDays < 7 => 10,
            default                => 0,
        };

        if ($s->priorChargebacks > 0) { $score += 40; }

        return min(self::MAX_SCORE, $score);
    }

    public function bucket(int $score): FraudBucket
    {
        return match (true) {
            $score >= self::DECLINE_THRESHOLD => FraudBucket::Decline,
            $score >= self::REVIEW_THRESHOLD  => FraudBucket::Review,
            default                            => FraudBucket::Accept,
        };
    }
}
