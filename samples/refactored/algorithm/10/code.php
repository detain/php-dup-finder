<?php

declare(strict_types=1);

namespace Acme\Common\Allocation;

final class FifoConsumer
{
    /**
     * @template TLot of object
     * @template TDraw of object
     * @param TLot[]                          $lots
     * @param callable(TLot):int              $orderKey
     * @param callable(TLot):int              $available
     * @param callable(TLot,int,string):TDraw $emit
     * @return list<TDraw>
     */
    public function consume(
        array $lots,
        int $demand,
        string $reference,
        callable $orderKey,
        callable $available,
        callable $emit,
    ): array {
        usort($lots, static fn(object $a, object $b): int => $orderKey($a) <=> $orderKey($b));

        $draws = [];
        $remaining = $demand;

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $qty = $available($lot);
            if ($qty <= 0) {
                continue;
            }

            $take = min($remaining, $qty);
            $remaining -= $take;
            $draws[] = $emit($lot, $take, $reference);
        }

        if ($remaining > 0) {
            throw new \RuntimeException(
                "Allocation short by {$remaining} for reference {$reference}",
            );
        }

        return $draws;
    }
}
