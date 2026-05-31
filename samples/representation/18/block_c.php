<?php
declare(strict_types=1);

namespace App\Subscription\ViewModel;

final class SubscriptionViewModel
{
    public string $id;
    public string $planName;
    public string $statusLabel;
    public string $statusClass;
    public string $formattedAmount;
    public string $billingInterval;
    public string $renewalDate;
    public string $renewalCountdown;
    public string $progressPercent;
    public bool $isActive;
    public bool $isPastDue;
    public bool $isCancelled;
    public bool $isTrialing;
    public bool $canCancel;
    public bool $canChangePlan;
    public bool $canPause;
    public array $features;

    public static function fromDTO(SubscriptionDTO $dto): self
    {
        $vm = new self();
        $vm->id = $dto->id;
        $vm->planName = $dto->planName;
        $vm->statusLabel = self::getStatusLabel($dto->status);
        $vm->statusClass = self::getStatusClass($dto->status);
        $vm->formattedAmount = $dto->getFormattedAmount();
        $vm->billingInterval = ucfirst($dto->interval);
        $vm->renewalDate = $dto->getRenewalDate();
        $vm->renewalCountdown = self::formatCountdown($dto->daysUntilRenewal);
        $vm->progressPercent = self::calculateProgress($dto->daysUntilRenewal);
        $vm->isActive = $dto->isActive;
        $vm->isPastDue = $dto->isPastDue;
        $vm->isCancelled = $dto->status === 'cancelled';
        $vm->isTrialing = $dto->status === 'trialing';
        $vm->canCancel = $dto->isCancellable();
        $vm->canChangePlan = $dto->isActive;
        $vm->canPause = $dto->isActive;
        $vm->features = $dto->features;

        return $vm;
    }

    private static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'past_due' => 'Past Due',
            'cancelled' => 'Cancelled',
            'trialing' => 'Trial',
            'paused' => 'Paused',
            default => ucfirst($status)
        };
    }

    private static function getStatusClass(string $status): string
    {
        return match ($status) {
            'active' => 'badge-success',
            'past_due' => 'badge-warning',
            'cancelled' => 'badge-secondary',
            'trialing' => 'badge-info',
            'paused' => 'badge-secondary',
            default => 'badge-secondary'
        };
    }

    private static function formatCountdown(int $days): string
    {
        if ($days === 0) {
            return 'Today';
        }

        if ($days === 1) {
            return 'Tomorrow';
        }

        if ($days < 7) {
            return "in {$days} days";
        }

        if ($days < 30) {
            $weeks = (int) floor($days / 7);
            return "in {$weeks} week" . ($weeks > 1 ? 's' : '');
        }

        $months = (int) floor($days / 30);
        return "in {$months} month" . ($months > 1 ? 's' : '');
    }

    private static function calculateProgress(int $daysRemaining): int
    {
        $totalDays = 30;
        $elapsed = $totalDays - $daysRemaining;

        return min(100, max(0, (int) (($elapsed / $totalDays) * 100)));
    }

    public function getCardClass(): string
    {
        if ($this->isPastDue) {
            return 'border-warning';
        }

        if ($this->isCancelled) {
            return 'border-secondary';
        }

        return 'border-success';
    }

    public function toViewData(): array
    {
        return [
            'id' => $this->id,
            'plan_name' => $this->planName,
            'status_label' => $this->statusLabel,
            'status_class' => $this->statusClass,
            'formatted_amount' => $this->formattedAmount,
            'billing_interval' => $this->billingInterval,
            'renewal_date' => $this->renewalDate,
            'renewal_countdown' => $this->renewalCountdown,
            'progress_percent' => $this->progressPercent,
            'is_active' => $this->isActive,
            'is_past_due' => $this->isPastDue,
            'can_cancel' => $this->canCancel,
            'can_change_plan' => $this->canChangePlan,
            'features' => $this->features,
            'card_class' => $this->getCardClass()
        ];
    }
}
