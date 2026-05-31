<?php

declare(strict_types=1);

namespace App\Search;

use Psr\Log\LoggerInterface;

final class InterpolationSearchService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Searches for a target value in a sorted array using interpolation search.
     *
     * This implementation estimates the position based on the value.
     * Works best for uniformly distributed sorted data.
     * Time complexity: O(log log n) average, O(n) worst case.
     */
    public function search(array $sortedArray, int $target): ?int
    {
        $left = 0;
        $right = count($sortedArray) - 1;

        while ($left <= $right && $target >= $sortedArray[$left] && $target <= $sortedArray[$right]) {
            if ($left === $right) {
                if ($sortedArray[$left] === $target) {
                    return $left;
                }
                return null;
            }

            $pos = $left + (int) floor(
                (($target - $sortedArray[$left]) * ($right - $left))
                / ($sortedArray[$right] - $sortedArray[$left])
            );

            if ($sortedArray[$pos] === $target) {
                $this->logger->debug('Interpolation search found target', [
                    'target' => $target,
                    'index' => $pos,
                ]);
                return $pos;
            }

            if ($sortedArray[$pos] < $target) {
                $left = $pos + 1;
            } else {
                $right = $pos - 1;
            }
        }

        $this->logger->debug('Interpolation search did not find target', [
            'target' => $target,
        ]);

        return null;
    }

    /**
     * Estimates the position of target in the array.
     */
    public function estimatePosition(array $sortedArray, int $target): ?int
    {
        if (count($sortedArray) === 0) {
            return null;
        }

        return (int) floor(
            (($target - $sortedArray[0]) * (count($sortedArray) - 1))
            / ($sortedArray[count($sortedArray) - 1] - $sortedArray[0])
        );
    }
}
