<?php
declare(strict_types=1);

namespace Acme\Lending\Fees;

use Acme\Lending\Domain\LoanApplication;

final class OriginationFeeCalculator
{
    public function __construct(
        private readonly float $floor = 75.00,
    ) {
    }

    public function fee(LoanApplication $application): float
    {
        $principal = $application->principalAmount();

        // identical token-shape: cascading if/elseif tiers on amount
        if ($principal < 5_000.0) {
            return $this->floor + 25.00;
        } elseif ($principal < 25_000.0) {
            return $this->floor + 150.00;
        } elseif ($principal < 100_000.0) {
            return $this->floor + 625.00;
        } else {
            return $this->floor + 2_400.00;
        }
    }

    /**
     * @param iterable<LoanApplication> $apps
     */
    public function feesTotal(iterable $apps): float
    {
        $sum = 0.0;
        foreach ($apps as $application) {
            $sum += $this->fee($application);
        }
        return $sum;
    }
}
