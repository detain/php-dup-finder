<?php

declare(strict_types=1);

namespace App\Text;

use Psr\Log\LoggerInterface;

final class IterativeTextProcessor
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Reverses a string using iterative character building.
     *
     * This implementation uses a loop to build the reversed string
     * by appending characters from the end of the input.
     */
    public function reverseString(string $input): string
    {
        $result = '';
        $length = strlen($input);

        for ($i = $length - 1; $i >= 0; $i--) {
            $result .= $input[$i];
        }

        return $result;
    }

    /**
     * Counts the number of words using iterative scanning.
     */
    public function countWords(string $input): int
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return 0;
        }

        $count = 1;

        for ($i = 0; $i < strlen($trimmed); $i++) {
            if ($trimmed[$i] === ' ' && isset($trimmed[$i + 1]) && $trimmed[$i + 1] !== ' ') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Checks if a string is a palindrome using iterative comparison.
     */
    public function isPalindrome(string $input): bool
    {
        $cleaned = strtolower(preg_replace('/[^a-z0-9]/', '', $input));
        $length = strlen($cleaned);

        if ($length <= 1) {
            return true;
        }

        $left = 0;
        $right = $length - 1;

        while ($left < $right) {
            if ($cleaned[$left] !== $cleaned[$right]) {
                return false;
            }
            $left++;
            $right--;
        }

        return true;
    }
}
