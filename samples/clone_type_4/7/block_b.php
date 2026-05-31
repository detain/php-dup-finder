<?php

declare(strict_types=1);

namespace App\Search;

use Psr\Log\LoggerInterface;

final class LinearSearchService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Searches for a target value in an array using linear search.
     *
     * This implementation checks each element sequentially.
     * Time complexity: O(n)
     * Space complexity: O(1)
     */
    public function search(array $array, int $target): ?int
    {
        $count = count($array);

        for ($i = 0; $i < $count; $i++) {
            if ($array[$i] === $target) {
                $this->logger->debug('Linear search found target', [
                    'target' => $target,
                    'index' => $i,
                ]);
                return $i;
            }
        }

        $this->logger->debug('Linear search did not find target', [
            'target' => $target,
        ]);

        return null;
    }

    /**
     * Finds all occurrences of target in array.
     */
    public function findAll(array $array, int $target): array
    {
        $indices = [];
        $count = count($array);

        for ($i = 0; $i < $count; $i++) {
            if ($array[$i] === $target) {
                $indices[] = $i;
            }
        }

        return $indices;
    }

    /**
     * Counts occurrences of target in array.
     */
    public function count(array $array, int $target): int
    {
        $count = 0;
        $arrayCount = count($array);

        for ($i = 0; $i < $arrayCount; $i++) {
            if ($array[$i] === $target) {
                $count++;
            }
        }

        return $count;
    }
}
