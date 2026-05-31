<?php
declare(strict_types=1);

namespace Acme\Insurance\Premium;

use Acme\Insurance\Domain\Policy;

final class PremiumPricingService
{
    public function __construct(
        private readonly float $minimumPremium = 25.00,
    ) {
    }

    public function quote(Policy $policy): float
    {
        $coverage = $policy->coverageAmount();

        // same lexeme stream: if/elseif/elseif/else over amount tiers
        if ($coverage < 10_000.0) {
            return $this->minimumPremium + 12.00;
        } elseif ($coverage < 50_000.0) {
            return $this->minimumPremium + 65.00;
        } elseif ($coverage < 250_000.0) {
            return $this->minimumPremium + 280.00;
        } else {
            return $this->minimumPremium + 950.00;
        }
    }

    /**
     * @param iterable<Policy> $policies
     */
    public function totalQuoted(iterable $policies): float
    {
        $sum = 0.0;
        foreach ($policies as $policy) {
            $sum += $this->quote($policy);
        }
        return $sum;
    }
}
