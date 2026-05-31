<?php
declare(strict_types=1);

namespace App\Reports\Sales;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

final class DailySalesReport
{
    private const APPROVED_STATUSES = ['paid', 'shipped', 'delivered'];

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
            $rows = Order::query()
                ->select(['id', 'reference', 'total_cents', 'currency', 'customer_id', 'created_at'])
                ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
                ->whereIn('status', self::APPROVED_STATUSES)
                ->orderBy('created_at')
                ->get();
        } catch (Throwable $e) {
            $this->logger->error('Daily sales query failed', [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $rows->map(static fn (Order $order): array => [
            'id' => $order->id,
            'reference' => $order->reference,
            'total' => $order->total_cents / 100,
            'currency' => $order->currency,
            'day' => $order->created_at?->toDateString(),
        ]);
    }
}
