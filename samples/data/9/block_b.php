<?php
declare(strict_types=1);

namespace App\Fraud\AccountTakeover;

use App\Fraud\BehaviourModel;
use App\Database\Connection;

final class LoginAttemptAnalyzer
{
    public function __construct(
        private BehaviourModel $model,
        private Connection $db,
    ) {
    }

    public function analyzeRecent(int $userId): array
    {
        $attempts = $this->db->fetchAll(
            'SELECT id, ip, user_agent, success, occurred_at, geo_country
             FROM login_attempts
             WHERE user_id = ?
               AND occurred_at >= NOW() - INTERVAL 24 HOUR
             ORDER BY occurred_at DESC',
            [$userId]
        );

        if ($attempts === []) {
            return ['risk' => 0.0, 'suspicious' => []];
        }

        $withScores = array_map(
            fn(array $a) => [
                'attempt' => $a,
                'risk'    => $this->model->riskScore($a),
            ],
            $attempts
        );

        $suspicious = array_filter(
            $withScores,
            fn(array $row) => $row['risk'] >= 0.85
        );

        $overallRisk = array_reduce(
            $withScores,
            fn(float $carry, array $row) => max($carry, (float)$row['risk']),
            0.0
        );

        if ($overallRisk >= 0.85) {
            $this->db->execute(
                'UPDATE users SET account_locked = 1, locked_reason = ?, locked_at = NOW() WHERE id = ?',
                ['account_takeover_signal', $userId]
            );
        }

        return [
            'risk'            => $overallRisk,
            'suspicious_ids'  => array_map(fn($s) => (int)$s['attempt']['id'], $suspicious),
            'threshold_used'  => 0.85,
        ];
    }
}
