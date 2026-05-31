<?php
declare(strict_types=1);

namespace Acme\PaymentService\Fraud;

use Acme\PaymentService\Source\TransactionSignals;

final class TransactionRiskScreener
{
    public function __construct(private readonly TransactionSignals $signals)
    {
    }

    public function screen(string $txnId): array
    {
        $s = $this->signals->fetch($txnId);
        $risk = 0;

        if ($s['orders_last_hour'] > 5) {
            $risk += 30;
        } elseif ($s['orders_last_hour'] > 2) {
            $risk += 15;
        }

        if ($s['billing_country'] !== $s['shipping_country']) {
            $risk += 20;
        }
        if ($s['ip_country'] !== $s['billing_country']) {
            $risk += 15;
        }

        if (in_array($s['bin_bucket'], ['prepaid', 'gift'], true)) {
            $risk += 25;
        }

        if ($s['account_age_days'] < 1) {
            $risk += 20;
        } elseif ($s['account_age_days'] < 7) {
            $risk += 10;
        }

        if ($s['prior_chargebacks'] > 0) {
            $risk += 40;
        }

        $risk = min(100, $risk);
        $verdict = 'capture';
        if ($risk >= 70) { $verdict = 'reject'; }
        elseif ($risk >= 40) { $verdict = 'manual_review'; }

        return ['score' => $risk, 'verdict' => $verdict];
    }
}
