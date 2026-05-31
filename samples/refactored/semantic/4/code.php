<?php

declare(strict_types=1);

namespace Acme\Shared\Policy;

use Acme\Shared\Model\Customer;

final class CustomerRiskPolicy
{
    public function __construct(
        private int $fraudScoreThreshold = 80,
        private int $chargebackThreshold = 2,
        private int $velocityThreshold = 5,
        private int $lookbackDays = 90,
    ) {
    }

    public function isHighRisk(Customer $customer): bool
    {
        if ($customer->fraudScore() >= $this->fraudScoreThreshold) {
            return true;
        }

        if ($customer->chargebackCount($this->lookbackDays) >= $this->chargebackThreshold) {
            return true;
        }

        if ($customer->purchasesInLastHour() >= $this->velocityThreshold) {
            return true;
        }

        return false;
    }

    public function isTrusted(Customer $c): bool
    {
        return !$this->isHighRisk($c);
    }
}

final class FraudScreeningWorker
{
    public function __construct(private CustomerRiskPolicy $policy) {}
    public function shouldFlag(Customer $c): bool { return $this->policy->isHighRisk($c); }
}
