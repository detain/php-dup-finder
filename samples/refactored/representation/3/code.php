<?php
declare(strict_types=1);

namespace App\Subscription;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Trialing = 'trialing';
}

final class Subscription
{
    public function __construct(
        public readonly string $id,
        public readonly string $customerId,
        public readonly string $planCode,
        public readonly SubscriptionStatus $status,
        public readonly \DateTimeImmutable $periodStart,
        public readonly \DateTimeImmutable $periodEnd,
        public readonly ?\DateTimeImmutable $trialEnd,
        public readonly bool $cancelAtPeriodEnd,
    ) {
        if ($id === '' || $customerId === '') {
            throw new \InvalidArgumentException('Missing identifiers');
        }
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (string)$row['id'],
            (string)$row['customer_id'],
            (string)$row['plan_code'],
            SubscriptionStatus::from((string)$row['status']),
            new \DateTimeImmutable((string)$row['period_start']),
            new \DateTimeImmutable((string)$row['period_end']),
            !empty($row['trial_end']) ? new \DateTimeImmutable((string)$row['trial_end']) : null,
            (bool)($row['cancel_at_period_end'] ?? false),
        );
    }

    public function isEntitled(\DateTimeImmutable $at): bool
    {
        if ($this->status === SubscriptionStatus::Canceled) return false;
        if ($this->status === SubscriptionStatus::Trialing && $this->trialEnd !== null) {
            return $at <= $this->trialEnd;
        }
        return $at >= $this->periodStart && $at <= $this->periodEnd;
    }

    public function periodHuman(): string
    {
        return $this->periodStart->format('M j') . ' – ' . $this->periodEnd->format('M j, Y');
    }
}
