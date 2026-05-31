<?php
declare(strict_types=1);

namespace App\Reports\Refunds;

use App\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

final class RefundsReport
{
    private const APPROVED_STATUSES = ['approved', 'completed', 'settled'];

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
            $rows = Refund::query()
                ->select(['id', 'order_id', 'amount_cents', 'reason_code', 'created_at'])
                ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
                ->whereIn('status', self::APPROVED_STATUSES)
                ->orderBy('created_at')
                ->get();
        } catch (Throwable $e) {
            $this->logger->error('Refunds query failed', [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $rows->map(static fn (Refund $refund): array => [
            'id' => $refund->id,
            'order_id' => $refund->order_id,
            'amount' => $refund->amount_cents / 100,
            'reason' => $refund->reason_code,
            'day' => $refund->created_at?->toDateString(),
        ]);
    }
}
