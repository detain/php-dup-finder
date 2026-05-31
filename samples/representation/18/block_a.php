<?php
declare(strict_types=1);

namespace App\Subscription\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'subscriptions')]
#[ORM\Index(columns: ['customer_id', 'status'], name: 'idx_subs_customer_status')]
class Subscription
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_PAUSED = 'paused';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $customerId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $planId;

    #[ORM\Column(type: 'string', length: 50)]
    private string $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $currentPeriodStart;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $currentPeriodEnd;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $trialEnd = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $amount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 20)]
    private string $interval;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $paymentMethodId = null;

    public function __construct(
        string $id,
        string $customerId,
        string $planId,
        float $amount,
        string $currency,
        string $interval
    ) {
        $this->id = $id;
        $this->customerId = $customerId;
        $this->planId = $planId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->interval = $interval;
        $this->status = self::STATUS_ACTIVE;
        $this->currentPeriodStart = new \DateTimeImmutable();
        $this->currentPeriodEnd = (new \DateTimeImmutable())->modify('+1 ' . $interval);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function getPlanId(): string
    {
        return $this->planId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCurrentPeriodStart(): \DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function getCurrentPeriodEnd(): \DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isTrialing(): bool
    {
        return $this->status === self::STATUS_TRIALING;
    }

    public function isPastDue(): bool
    {
        return $this->status === self::STATUS_PAST_DUE;
    }

    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancelledAt = new \DateTimeImmutable();
    }

    public function pause(): void
    {
        $this->status = self::STATUS_PAUSED;
    }

    public function resume(): void
    {
        if ($this->status === self::STATUS_PAUSED) {
            $this->status = self::STATUS_ACTIVE;
        }
    }

    public function markAsPastDue(): void
    {
        $this->status = self::STATUS_PAST_DUE;
    }

    public function renew(): void
    {
        $this->currentPeriodStart = $this->currentPeriodEnd;
        $this->currentPeriodEnd = $this->currentPeriodEnd->modify('+1 ' . $this->interval);
        $this->status = self::STATUS_ACTIVE;
    }

    public function getDaysUntilRenewal(): int
    {
        $now = new \DateTimeImmutable();
        $diff = $this->currentPeriodEnd->getTimestamp() - $now->getTimestamp();

        return max(0, (int) floor($diff / 86400));
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'plan_id' => $this->planId,
            'status' => $this->status,
            'current_period_start' => $this->currentPeriodStart->format('c'),
            'current_period_end' => $this->currentPeriodEnd->format('c'),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'interval' => $this->interval
        ];
    }
}
