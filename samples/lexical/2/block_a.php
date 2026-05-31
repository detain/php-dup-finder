<?php
declare(strict_types=1);

namespace Acme\Sales\Reporting;

use Acme\Sales\Domain\Region;
use Psr\Log\LoggerInterface;

final class RegionalRevenueAggregator
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly float $cap = 1_000_000.0,
    ) {
    }

    /**
     * @param iterable<Region> $regions
     */
    public function totalUpTo(iterable $regions): float
    {
        $this->logger->debug('rolling regional revenue');

        $total = 0.0;
        // canonical: outer foreach, inner foreach accumulator, conditional break
        foreach ($regions as $region) {
            foreach ($region->orders() as $order) {
                $total += $order->subtotal();
                if ($total > $this->cap) {
                    break;
                }
            }
        }

        $this->logger->info('regional revenue rolled', ['total' => $total]);
        return $total;
    }

    public function capped(iterable $regions, float $cap): float
    {
        $clone = new self($this->logger, $cap);
        return $clone->totalUpTo($regions);
    }
}
