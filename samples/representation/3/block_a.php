<?php
declare(strict_types=1);

namespace Billing\Subscription;

final class Subscription
{
    public string $id;
    public string $customerId;
    public string $planCode;
    public string $status;
    public \DateTimeImmutable $currentPeriodStart;
    public \DateTimeImmutable $currentPeriodEnd;
    public ?\DateTimeImmutable $trialEnd;
    public bool $cancelAtPeriodEnd;

    public function __construct(array $data)
    {
        if (empty($data['id']) || empty($data['customer_id'])) {
            throw new \InvalidArgumentException('Missing identifiers');
        }
        if (!in_array($data['status'] ?? '', ['active', 'past_due', 'canceled', 'trialing'], true)) {
            throw new \InvalidArgumentException('Bad status');
        }
        $this->id = (string)$data['id'];
        $this->customerId = (string)$data['customer_id'];
        $this->planCode = (string)$data['plan_code'];
        $this->status = (string)$data['status'];
        $this->currentPeriodStart = new \DateTimeImmutable((string)$data['period_start']);
        $this->currentPeriodEnd = new \DateTimeImmutable((string)$data['period_end']);
        $this->trialEnd = !empty($data['trial_end']) ? new \DateTimeImmutable((string)$data['trial_end']) : null;
        $this->cancelAtPeriodEnd = (bool)($data['cancel_at_period_end'] ?? false);
    }

    public function renew(\DateInterval $interval): void
    {
        if ($this->cancelAtPeriodEnd) {
            $this->status = 'canceled';
            return;
        }
        $this->currentPeriodStart = $this->currentPeriodEnd;
        $this->currentPeriodEnd = $this->currentPeriodEnd->add($interval);
        $this->status = 'active';
    }
}

final class BillingCycleRunner
{
    public function processRenewal(Subscription $sub): void
    {
        $sub->renew(new \DateInterval('P1M'));
    }
}
