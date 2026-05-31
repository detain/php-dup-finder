<?php
declare(strict_types=1);

namespace App\Fraud\Chargebacks;

use App\Fraud\ChargebackClassifier;
use App\Database\Connection;

final class ChargebackDisputeRouter
{
    public function __construct(
        private ChargebackClassifier $classifier,
        private Connection $db,
    ) {
    }

    public function route(array $chargebackIds): array
    {
        $chargebacks = $this->db->fetchAll(
            'SELECT id, transaction_id, reason_code, amount_cents, opened_at, evidence
             FROM chargebacks
             WHERE id IN (' . implode(',', array_map('intval', $chargebackIds)) . ')'
        );

        $classified = array_map(
            fn(array $cb) => [
                'chargeback' => $cb,
                'win_proba'  => $this->classifier->predictWinProbability($cb),
            ],
            $chargebacks
        );

        $disputable = array_filter(
            $classified,
            fn(array $c) => $c['win_proba'] >= 0.85
        );

        $accept = array_filter(
            $classified,
            fn(array $c) => $c['win_proba'] < 0.85
        );

        foreach ($disputable as $entry) {
            $this->db->execute(
                'UPDATE chargebacks SET disposition = ?, dispute_due_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?',
                ['dispute_recommended', (int)$entry['chargeback']['id']]
            );
        }

        foreach ($accept as $entry) {
            $this->db->execute(
                'UPDATE chargebacks SET disposition = ?, decided_at = NOW() WHERE id = ?',
                ['accept_loss', (int)$entry['chargeback']['id']]
            );
        }

        $summaryCounts = array_reduce(
            $classified,
            function (array $carry, array $row): array {
                if ($row['win_proba'] >= 0.85) {
                    $carry['dispute']++;
                } else {
                    $carry['accept']++;
                }
                return $carry;
            },
            ['dispute' => 0, 'accept' => 0]
        );

        return $summaryCounts + ['threshold' => 0.85];
    }
}
