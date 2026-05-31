<?php
declare(strict_types=1);

namespace App\Subscription\Model;

use App\Subscription\Entity\Subscription;

final class SubscriptionModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $customerId,
        public readonly string $planId,
        public readonly string $status,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $interval,
        public readonly \DateTimeImmutable $currentPeriodStart,
        public readonly \DateTimeImmutable $currentPeriodEnd,
        public readonly ?\DateTimeImmutable $trialEnd = null,
        public readonly ?\DateTimeImmutable $cancelledAt = null
    ) {}

    public static function fromEntity(Subscription $subscription): self
    {
        return new self(
            id: $subscription->getId(),
            customerId: $subscription->getCustomerId(),
            planId: $subscription->getPlanId(),
            status: $subscription->getStatus(),
            amount: $subscription->getAmount(),
            currency: $subscription->getCurrency(),
            interval: $subscription->getInterval(),
            currentPeriodStart: $subscription->getCurrentPeriodStart(),
            currentPeriodEnd: $subscription->getCurrentPeriodEnd()
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getDaysUntilRenewal(): int
    {
        $diff = $this->currentPeriodEnd->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
        return max(0, (int) floor($diff / 86400));
    }

    public function toDTO(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'days_until_renewal' => $this->getDaysUntilRenewal()
        ];
    }
}
