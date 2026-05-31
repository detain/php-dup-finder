<?php

declare(strict_types=1);

namespace App\Text;

use Psr\Log\LoggerInterface;

interface TextProcessorInterface
{
    public function reverseString(string $input): string;
    public function countWords(string $input): int;
    public function isPalindrome(string $input): bool;
}

abstract class AbstractTextProcessor implements TextProcessorInterface
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}
}

final class RecursiveTextProcessor extends AbstractTextProcessor
{
    public function reverseString(string $input): string
    {
        if (strlen($input) <= 1) {
            return $input;
        }

        return substr($input, -1) . $this->reverseString(substr($input, 0, -1));
    }

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

        return 1 + $this->countWords(substr($trimmed, $spacePos + 1));
    }

    public function isPalindrome(string $input): bool
    {
        $cleaned = $this->normalizeForPalindrome($input);

        if (strlen($cleaned) <= 1) {
            return true;
        }

        return $cleaned[0] === $cleaned[-1]
            && $this->isPalindrome(substr($cleaned, 1, -1));
    }

    private function normalizeForPalindrome(string $input): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/', '', $input));
    }
}

final class TextProcessorFactory
{
    public static function create(string $strategy): TextProcessorInterface
    {
        $logger = new \Psr\Log\NullLogger();

        return match ($strategy) {
            'recursive' => new RecursiveTextProcessor($logger),
            'iterative' => new IterativeTextProcessor($logger),
            'array' => new ArrayTextProcessor($logger),
            default => throw new \InvalidArgumentException("Unknown strategy: {$strategy}"),
        };
    }
}
