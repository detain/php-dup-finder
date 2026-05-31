<?php

declare(strict_types=1);

namespace Acme\Loyalty\Rewards;

use Acme\Loyalty\Model\Member;
use Acme\Loyalty\Repository\MemberRepository;
use Acme\Loyalty\Mailer\RewardMailer;

final class RewardDispatcher
{
    public function __construct(
        private MemberRepository $members,
        private RewardMailer $mailer,
    ) {
    }

    public function dispatchMonthlyRewards(): int
    {
        $sent = 0;

        foreach ($this->members->dueForReward() as $member) {
            if (!$member->isTrusted()) {
                continue;
            }

            $this->mailer->sendReward($member);
            $member->recordRewardSent();
            $this->members->save($member);
            $sent++;
        }

        return $sent;
    }
}
