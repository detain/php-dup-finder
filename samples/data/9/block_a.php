<?php
declare(strict_types=1);

namespace App\Fraud\Transactions;

use App\Fraud\ScoringClient;
use App\Database\Connection;

final class TransactionScreener
{
    public function __construct(
        private ScoringClient $scoring,
        private Connection $db,
    ) {
    }

    public function screenBatch(array $transactionIds): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, amount_cents, account_id, country, device_fingerprint
             FROM transactions
             WHERE id IN (' . implode(',', array_map('intval', $transactionIds)) . ')'
        );

        $scored = array_map(
            fn(array $row) => [
                'tx'    => $row,
                'score' => $this->scoring->scoreTransaction($row),
            ],
            $rows
        );

        $flagged = array_filter(
            $scored,
            fn(array $s) => $s['score'] >= 0.85
        );

        $allowed = array_filter(
            $scored,
            fn(array $s) => $s['score'] < 0.85
        );

        foreach ($flagged as $entry) {
            $this->db->execute(
                'INSERT INTO fraud_holds (transaction_id, score, reason, created_at)
                 VALUES (?, ?, ?, NOW())',
                [(int)$entry['tx']['id'], $entry['score'], 'auto_score_threshold']
            );
        }

        return [
            'flagged' => array_values(array_map(fn($e) => (int)$e['tx']['id'], $flagged)),
            'allowed' => array_values(array_map(fn($e) => (int)$e['tx']['id'], $allowed)),
            'threshold' => 0.85,
        ];
    }
}
