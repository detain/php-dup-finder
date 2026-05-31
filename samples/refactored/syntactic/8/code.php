<?php
declare(strict_types=1);

namespace Acme\Common;

/**
 * Generic bound-reducer aggregator. Subclasses (or callers) supply:
 *  - the initial accumulator (which encodes any "scope" key like region/cluster/poll)
 *  - a per-item reducer that may use $this->...
 *  - a finaliser that maps the final accumulator to a domain DTO.
 */
abstract class BoundReducerAggregator
{
    /**
     * @template T
     * @template R
     * @param array<int,T>                              $items
     * @param array<string,mixed>                       $initial
     * @param callable(array<string,mixed>,T):array<string,mixed> $reduce
     * @param callable(array<string,mixed>):R           $finalise
     * @return R
     */
    final protected function reduceBound(
        array $items,
        array $initial,
        callable $reduce,
        callable $finalise,
    ): mixed {
        $bound  = \Closure::bind(\Closure::fromCallable($reduce), $this, static::class);
        $result = array_reduce($items, $bound, $initial);

        return $finalise($result);
    }
}

/* CartPriceAggregator collapses to:
 *
 *  public function aggregate(array $lines, string $taxRegion): CartTotals
 *  {
 *      return $this->reduceBound(
 *          $lines,
 *          ['gross' => 0, 'tax' => 0, 'skuCount' => 0, 'region' => $taxRegion],
 *          fn(array $c, CartLine $l) => [
 *              'gross'    => $c['gross'] + $l->unitPrice * $l->quantity,
 *              'tax'      => $c['tax']   + (int) round($l->unitPrice * $l->quantity * $this->tax->rateFor($c['region'])),
 *              'skuCount' => $c['skuCount'] + 1,
 *              'region'   => $c['region'],
 *          ],
 *          fn(array $r) => new CartTotals($r['gross'], $r['tax'], $r['gross'] + $r['tax'], $r['skuCount']),
 *      );
 *  }
 */
