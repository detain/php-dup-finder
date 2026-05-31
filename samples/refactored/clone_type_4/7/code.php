<?php

declare(strict_types=1);

namespace App\Search;

use Psr\Log\LoggerInterface;

interface SearchStrategyInterface
{
    public function search(array $array, int $target): ?int;
    public function getName(): string;
}

abstract class AbstractSearchService
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    protected function logResult(string $strategy, ?int $index, int $target): void
    {
        $this->logger->debug("{$strategy} search result", [
            'target' => $target,
            'found' => $index !== null,
            'index' => $index,
        ]);
    }
}

final class BinarySearchService extends AbstractSearchService implements SearchStrategyInterface
{
    public function search(array $sortedArray, int $target): ?int
    {
        $left = 0;
        $right = count($sortedArray) - 1;

        while ($left <= $right) {
            $mid = (int) floor(($left + $right) / 2);

            if ($sortedArray[$mid] === $target) {
                $this->logResult('Binary', $mid, $target);
                return $mid;
            }

            if ($sortedArray[$mid] < $target) {
                $left = $mid + 1;
            } else {
                $right = $mid - 1;
            }
        }

        $this->logResult('Binary', null, $target);
        return null;
    }

    public function getName(): string
    {
        return 'binary';
    }
}

final class LinearSearchService extends AbstractSearchService implements SearchStrategyInterface
{
    public function search(array $array, int $target): ?int
    {
        foreach ($array as $index => $value) {
            if ($value === $target) {
                $this->logResult('Linear', $index, $target);
                return $index;
            }
        }

        $this->logResult('Linear', null, $target);
        return null;
    }

    public function getName(): string
    {
        return 'linear';
    }
}

final class InterpolationSearchService extends AbstractSearchService implements SearchStrategyInterface
{
    public function search(array $sortedArray, int $target): ?int
    {
        $left = 0;
        $right = count($sortedArray) - 1;

        while ($left <= $right && $target >= $sortedArray[$left] && $target <= $sortedArray[$right]) {
            if ($left === $right) {
                return $sortedArray[$left] === $target ? $left : null;
            }

            $pos = $left + (int) floor(
                (($target - $sortedArray[$left]) * ($right - $left))
                / ($sortedArray[$right] - $sortedArray[$left])
            );

            if ($sortedArray[$pos] === $target) {
                $this->logResult('Interpolation', $pos, $target);
                return $pos;
            }

            if ($sortedArray[$pos] < $target) {
                $left = $pos + 1;
            } else {
                $right = $pos - 1;
            }
        }

        $this->logResult('Interpolation', null, $target);
        return null;
    }

    public function getName(): string
    {
        return 'interpolation';
    }
}

final class SearchOrchestrator
{
    /** @var SearchStrategyInterface[] */
    private array $strategies = [];

    public function registerStrategy(SearchStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->getName()] = $strategy;
    }

    public function search(string $strategyName, array $array, int $target): ?int
    {
        $strategy = $this->strategies[$strategyName] ?? null;

        if ($strategy === null) {
            throw new \InvalidArgumentException("Unknown search strategy: {$strategyName}");
        }

        return $strategy->search($array, $target);
    }
}
