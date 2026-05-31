<?php
declare(strict_types=1);

namespace App\Subscription\DTO;

final class SubscriptionDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $customerId,
        public readonly string $planId,
        public readonly string $planName,
        public readonly string $status,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $interval,
        public readonly string $currentPeriodStart,
        public readonly string $currentPeriodEnd,
        public readonly ?string $trialEnd,
        public readonly bool $isActive,
        public readonly bool $isPastDue,
        public readonly int $daysUntilRenewal,
        public readonly string $nextBillingAmount,
        public readonly array $features = []
    ) {}

    public static function fromEntity(
        Subscription $subscription,
        string $planName,
        array $features = []
    ): self {
        return new self(
            id: $subscription->getId(),
            customerId: $subscription->getCustomerId(),
            planId: $subscription->getPlanId(),
            planName: $planName,
            status: $subscription->getStatus(),
            amount: $subscription->getAmount(),
            currency: $subscription->getCurrency(),
            interval: $subscription->getInterval(),
            currentPeriodStart: $subscription->getCurrentPeriodStart()->format('c'),
            currentPeriodEnd: $subscription->getCurrentPeriodEnd()->format('c'),
            trialEnd: null,
            isActive: $subscription->isActive(),
            isPastDue: $subscription->isPastDue(),
            daysUntilRenewal: $subscription->getDaysUntilRenewal(),
            nextBillingAmount: number_format($subscription->getAmount(), 2) . ' ' . $subscription->getCurrency(),
            features: $features
        );
    }

    public function isCancellable(): bool
    {
        return $this->isActive && !$this->isPastDue;
    }

    public function canResume(): bool
    {
        return $this->status === 'paused';
    }

    public function getFormattedAmount(): string
    {
        return number_format($this->amount, 2) . ' ' . $this->currency;
    }

    public function getRenewalDate(): string
    {
        $date = new \DateTimeImmutable($this->currentPeriodEnd);
        return $date->format('F j, Y');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'plan_id' => $this->planId,
            'plan_name' => $this->planName,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'interval' => $this->interval,
            'current_period_start' => $this->currentPeriodStart,
            'current_period_end' => $this->currentPeriodEnd,
            'trial_end' => $this->trialEnd,
            'is_active' => $this->isActive,
            'is_past_due' => $this->isPastDue,
            'days_until_renewal' => $this->daysUntilRenewal,
            'next_billing_amount' => $this->nextBillingAmount,
            'features' => $this->features
        ];
    }
}
