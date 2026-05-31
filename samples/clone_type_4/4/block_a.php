<?php

declare(strict_types=1);

namespace App\Text;

use Psr\Log\LoggerInterface;

final class RecursiveTextProcessor
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Reverses a string using recursive character extraction.
     *
     * This implementation builds the reversal by recursively
     * extracting the last character and prepending it to the result.
     */
    public function reverseString(string $input): string
    {
        if (strlen($input) <= 1) {
            return $input;
        }

        $lastChar = substr($input, -1);
        $remaining = substr($input, 0, -1);

        return $lastChar . $this->reverseString($remaining);
    }

    /**
     * Counts the number of words using recursive character scanning.
     */
    public function countWords(string $input): int
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return 0;
        }

        $spacePos = strpos($trimmed, ' ');

        if ($spacePos === false) {
            return 1;
        }

        $firstWord = substr($trimmed, 0, $spacePos);
        $restOfString = substr($trimmed, $spacePos + 1);

        return 1 + $this->countWords($restOfString);
    }

    /**
     * Checks if a string is a palindrome using recursive comparison.
     */
    public function isPalindrome(string $input): bool
    {
        $cleaned = strtolower(preg_replace('/[^a-z0-9]/', '', $input));

        if (strlen($cleaned) <= 1) {
            return true;
        }

        $firstChar = substr($cleaned, 0, 1);
        $lastChar = substr($cleaned, -1);

        if ($firstChar !== $lastChar) {
            return false;
        }

        $middle = substr($cleaned, 1, -1);

        return $this->isPalindrome($middle);
    }
}
