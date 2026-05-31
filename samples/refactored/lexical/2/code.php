<?php
declare(strict_types=1);

namespace Acme\Common\Aggregate;

final class CappedAccumulator
{
    /**
     * Outer-then-inner accumulation with a conditional break once `$cap` is crossed.
     *
     * @template TOuter
     * @template TInner
     * @param iterable<TOuter> $outer
     * @param callable(TOuter): iterable<TInner> $expand
     * @param callable(TInner): (int|float)     $valueOf
     */
    public static function sumUntil(
        iterable $outer,
        callable $expand,
        callable $valueOf,
        int|float $cap,
    ): int|float {
        $total = 0;
        foreach ($outer as $item) {
            foreach ($expand($item) as $inner) {
                $total += $valueOf($inner);
                if ($total > $cap) {
                    break;
                }
            }
        }
        return $total;
    }
}

// per-domain usage
// CappedAccumulator::sumUntil($regions, fn($r) => $r->orders(),  fn($o) => $o->subtotal(),       1_000_000.0);
// CappedAccumulator::sumUntil($devices, fn($d) => $d->readings(), fn($x) => $x->celsius(),       500.0);
// CappedAccumulator::sumUntil($streams, fn($s) => $s->entries(),  fn($e) => $e->severityWeight(), 100);
