<?php

declare(strict_types=1);

namespace Acme\Common\Loyalty;

final class CategoryAccrualEngine
{
    /**
     * @param array<string,float> $categoryRates
     * @param array<string,float> $tierMultipliers
     */
    public function __construct(
        private readonly array $categoryRates,
        private readonly array $tierMultipliers,
    ) {
    }

    /**
     * @template TLine of object
     * @param TLine[] $lines
     * @param callable(TLine):array{id:string|int, amount:float, category:string} $extract
     * @param callable(string|int, string, int):object $build
     * @return list<object>
     */
    public function accrue(array $lines, string $tier, callable $extract, callable $build): array
    {
        $multiplier = $this->tierMultipliers[$tier] ?? 1.0;
        $entries = [];

        foreach ($lines as $line) {
            $shape = $extract($line);
            $rate = $this->categoryRates[$shape['category']] ?? 0.0;
            $points = (int) floor($shape['amount'] * $rate * $multiplier);

            $entries[] = $build($shape['id'], $shape['category'], $points);
        }

        return $entries;
    }
}
