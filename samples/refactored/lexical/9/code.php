<?php
declare(strict_types=1);

namespace Acme\Common\Ranking;

/**
 * Walk a key=>value map and emit a list of [score, label] tuples, sorted by score desc.
 *
 * @template TValue
 */
final class TupleRanker
{
    /**
     * @param array<string, TValue>      $items
     * @param callable(string, TValue): array{0:int,1:string} $project
     * @return array<int, array{0:int,1:string}>
     */
    public static function rank(array $items, callable $project): array
    {
        $rows = [];
        foreach ($items as $key => $value) {
            $rows[] = $project($key, $value);
        }
        usort($rows, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        return $rows;
    }
}

// per-domain usage
// TupleRanker::rank($players, fn($id, $p)  => [$p->points(),   $id . ':' . $p->displayName()]);
// TupleRanker::rank($items,   fn($sku, $i) => [$i->onHand(),   $sku . ' — ' . $i->name()]);
// TupleRanker::rank($stats,   fn($t, $s)   => [$s->hitCount(), $t . ' (' . $s->locale() . ')']);
