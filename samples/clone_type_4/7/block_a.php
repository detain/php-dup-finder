<?php

declare(strict_types=1);

namespace App\Search;

use Psr\Log\LoggerInterface;

final class BinarySearchService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Searches for a target value in a sorted array using binary search.
     *
     * This implementation divides the search space in half with each iteration.
     * Time complexity: O(log n)
     * Space complexity: O(1)
     */
    public function search(array $sortedArray, int $target): ?int
    {
        $left = 0;
        $right = count($sortedArray) - 1;

        while ($left <= $right) {
            $mid = (int) floor(($left + $right) / 2);

            if ($sortedArray[$mid] === $target) {
                $this->logger->debug('Binary search found target', [
                    'target' => $target,
                    'index' => $mid,
                ]);
                return $mid;
            }

            if ($sortedArray[$mid] < $target) {
                $left = $mid + 1;
            } else {
                $right = $mid - 1;
            }
        }

        $this->logger->debug('Binary search did not find target', [
            'target' => $target,
        ]);

        return null;
    }

    /**
     * Finds the first occurrence of target in sorted array with duplicates.
     */
    public function findFirst(array $sortedArray, int $target): ?int
    {
        $left = 0;
        $right = count($sortedArray) - 1;
        $result = null;

        while ($left <= $right) {
            $mid = (int) floor(($left + $right) / 2);

            if ($sortedArray[$mid] === $target) {
                $result = $mid;
                $right = $mid - 1;
            } elseif ($sortedArray[$mid] < $target) {
                $left = $mid + 1;
            } else {
                $right = $mid - 1;
            }
        }

        return $result;
    }

    /**
     * Finds the last occurrence of target in sorted array with duplicates.
     */
    public function findLast(array $sortedArray, int $target): ?int
    {
        $left = 0;
        $right = count($sortedArray) - 1;
        $result = null;

        while ($left <= $right) {
            $mid = (int) floor(($left + $right) / 2);

            if ($sortedArray[$mid] === $target) {
                $result = $mid;
                $left = $mid + 1;
            } elseif ($sortedArray[$mid] < $target) {
                $left = $mid + 1;
            } else {
                $right = $mid - 1;
            }
        }

        return $result;
    }
}
