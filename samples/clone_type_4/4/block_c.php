<?php

declare(strict_types=1);

namespace App\Text;

use Psr\Log\LoggerInterface;

final class ArrayTextProcessor
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Reverses a string using array manipulation functions.
     *
     * This implementation converts the string to an array, reverses it,
     * and joins it back together.
     */
    public function reverseString(string $input): string
    {
        $chars = str_split($input);
        $reversed = array_reverse($chars);

        return implode('', $reversed);
    }

    /**
     * Counts the number of words using array filtering and counting.
     */
    public function countWords(string $input): string
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return 0;
        }

        $words = array_filter(explode(' ', $trimmed), fn($word) => $word !== '');

        return count($words);
    }

    /**
     * Checks if a string is a palindrome using array comparison.
     */
    public function isPalindrome(string $input): bool
    {
        $cleaned = strtolower(preg_replace('/[^a-z0-9]/', '', $input));
        $chars = str_split($cleaned);
        $reversedChars = array_reverse($chars);
        $reversed = implode('', $reversedChars);

        return $cleaned === $reversed;
    }
}
