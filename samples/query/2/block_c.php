<?php
declare(strict_types=1);

namespace App\Reports\Subscriptions;

use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

final class SubscriptionRenewalsReport
{
    private const APPROVED_STATUSES = ['active', 'renewed', 'trial_converted'];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function generate(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if ($from->greaterThan($to)) {
            throw new \InvalidArgumentException('from must be <= to');
        }

        try {
            $rows = Subscription::query()
                ->select(['id', 'customer_id', 'plan_code', 'mrr_cents', 'created_at'])
                ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
                ->whereIn('status', self::APPROVED_STATUSES)
                ->orderBy('created_at')
                ->get();
        } catch (Throwable $e) {
            $this->logger->error('Subscription renewals query failed', [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $rows->map(static fn (Subscription $sub): array => [
            'id' => $sub->id,
            'customer_id' => $sub->customer_id,
            'plan' => $sub->plan_code,
            'mrr' => $sub->mrr_cents / 100,
            'day' => $sub->created_at?->toDateString(),
        ]);
    }
}
