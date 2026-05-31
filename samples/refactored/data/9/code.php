<?php
declare(strict_types=1);

namespace App\Fraud;

final class RiskThresholds
{
    public const HIGH_RISK = 0.85;

    public static function isHighRisk(float $score): bool
    {
        return $score >= self::HIGH_RISK;
    }
}

namespace App\Fraud\Transactions;

use App\Fraud\RiskThresholds;
use App\Fraud\ScoringClient;

final class TransactionScreener
{
    public function __construct(private ScoringClient $scoring) {}

    public function screen(array $rows): array
    {
        $scored = array_map(
            fn($r) => ['tx' => $r, 'score' => $this->scoring->scoreTransaction($r)],
            $rows
        );
        $flagged = array_filter($scored, fn($s) => RiskThresholds::isHighRisk($s['score']));
        return ['flagged' => $flagged, 'threshold' => RiskThresholds::HIGH_RISK];
    }
}

namespace App\Fraud\AccountTakeover;

use App\Fraud\RiskThresholds;
use App\Fraud\BehaviourModel;

final class LoginAttemptAnalyzer
{
    public function __construct(private BehaviourModel $model) {}

    public function analyze(array $attempts): array
    {
        $scores = array_map(fn($a) => $this->model->riskScore($a), $attempts);
        $max = $scores === [] ? 0.0 : max($scores);
        return [
            'risk'   => $max,
            'locked' => RiskThresholds::isHighRisk($max),
        ];
    }
}

namespace App\Fraud\Chargebacks;

use App\Fraud\RiskThresholds;
use App\Fraud\ChargebackClassifier;

final class ChargebackDisputeRouter
{
    public function __construct(private ChargebackClassifier $classifier) {}

    public function route(array $chargebacks): array
    {
        $with = array_map(
            fn($cb) => ['cb' => $cb, 'win' => $this->classifier->predictWinProbability($cb)],
            $chargebacks
        );
        $disputable = array_filter($with, fn($e) => RiskThresholds::isHighRisk($e['win']));
        return ['disputable_count' => count($disputable)];
    }
}
