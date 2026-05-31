<?php

declare(strict_types=1);

namespace Acme\Common\Ranking;

final class WeightedRanker
{
    /**
     * @param array<string,float> $weights
     */
    public function __construct(private readonly array $weights)
    {
    }

    /**
     * @template TItem
     * @param TItem[] $items
     * @param callable(TItem):array<string,float> $extract
     * @param callable(TItem,float,array<string,float>):object $build
     * @return list<object>
     */
    public function rank(array $items, callable $extract, callable $build): array
    {
        $ranked = [];
        foreach ($items as $item) {
            $features = $extract($item);
            $score = 0.0;
            foreach ($features as $name => $value) {
                $score += $value * ($this->weights[$name] ?? 0.0);
            }
            $ranked[] = $build($item, round($score, 4), $features);
        }

        usort($ranked, static fn(object $a, object $b): int => $b->score <=> $a->score);

        return $ranked;
    }
}
