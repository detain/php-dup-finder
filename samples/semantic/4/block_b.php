<?php

declare(strict_types=1);

namespace Acme\Support\Tools;

use Acme\Support\Model\Account;
use Acme\Support\Service\AccountLookup;
use Acme\Support\Enum\RiskBand;

final class SupportConsoleController
{
    public function __construct(private AccountLookup $lookup)
    {
    }

    public function show(int $accountId): array
    {
        $account = $this->lookup->byId($accountId);

        $band = $account->riskBand();
        $hasVelocityFlag = $account->hasFlag('VELOCITY_SPIKE');
        $hasChargebackFlag = $account->disputes()->recent(90)->count() >= 2;

        $needsCaution = $band === RiskBand::HIGH
            || $hasVelocityFlag
            || $hasChargebackFlag;

        return [
            'account' => $account->id(),
            'risk' => $band->value,
            'caution_banner' => $needsCaution,
            'can_self_serve' => !$needsCaution,
        ];
    }
}
