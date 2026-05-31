<?php
declare(strict_types=1);

namespace Auth\Access;

final class SubscriptionAccessDto
{
    public function __construct(
        public readonly string $subscription_id,
        public readonly string $user_id,
        public readonly string $plan,
        public readonly string $state,
        public readonly int $periodStartTs,
        public readonly int $periodEndTs,
        public readonly ?int $trialEndsTs,
        public readonly bool $willCancel,
    ) {
        if ($subscription_id === '' || $user_id === '') {
            throw new \InvalidArgumentException('Missing identifiers');
        }
        if (!in_array($state, ['active', 'past_due', 'canceled', 'trialing'], true)) {
            throw new \InvalidArgumentException('Bad state');
        }
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string)$a['id'],
            (string)$a['customer_id'],
            (string)$a['plan_code'],
            (string)$a['status'],
            (int)strtotime((string)$a['period_start']),
            (int)strtotime((string)$a['period_end']),
            !empty($a['trial_end']) ? (int)strtotime((string)$a['trial_end']) : null,
            (bool)($a['cancel_at_period_end'] ?? false),
        );
    }

    public function isEntitled(): bool
    {
        if ($this->state === 'canceled') {
            return false;
        }
        $now = time();
        if ($this->state === 'trialing' && $this->trialEndsTs !== null) {
            return $now <= $this->trialEndsTs;
        }
        return $now >= $this->periodStartTs && $now <= $this->periodEndTs;
    }
}

final class AccessGate
{
    public function canAccess(SubscriptionAccessDto $sub, string $feature): bool
    {
        return $sub->isEntitled() && $sub->plan !== 'free';
    }
}
