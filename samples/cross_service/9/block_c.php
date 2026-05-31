<?php
declare(strict_types=1);

namespace Acme\RiskService\Analytics;

use Acme\RiskService\Warehouse\SignalsWarehouse;

final class RiskScoreReporter
{
    public function __construct(private readonly SignalsWarehouse $warehouse)
    {
    }

    public function categorize(string $eventId): string
    {
        $f = $this->warehouse->signals($eventId);
        $points = 0;

        if ($f['velocity_1h'] > 5) {
            $points += 30;
        } elseif ($f['velocity_1h'] > 2) {
            $points += 15;
        }

        if ($f['country_billing'] !== $f['country_shipping']) {
            $points += 20;
        }
        if ($f['country_ip'] !== $f['country_billing']) {
            $points += 15;
        }

        if (in_array($f['bin_class'], ['prepaid', 'gift'], true)) {
            $points += 25;
        }

        $age = (int) $f['acct_age_days'];
        if ($age < 1) {
            $points += 20;
        } elseif ($age < 7) {
            $points += 10;
        }

        if ($f['chargebacks_count'] > 0) {
            $points += 40;
        }

        $points = min(100, $points);
        if ($points >= 70) {
            return 'high';
        }
        if ($points >= 40) {
            return 'medium';
        }
        return 'low';
    }
}
