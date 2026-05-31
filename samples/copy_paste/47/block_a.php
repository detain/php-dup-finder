<?php

declare(strict_types=1);

namespace App\Content;

final class StringTruncator
{
    public function truncate(string $text, int $maxLength, string $suffix = '...'): string
    {
        if ($maxLength < 0) {
            throw new \InvalidArgumentException('Max length cannot be negative');
        }

        if ($maxLength === 0) {
            return '';
        }

        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $availableLength = $maxLength - strlen($suffix);

        if ($availableLength <= 0) {
            return substr($suffix, 0, $maxLength);
        }

        return substr($text, 0, $availableLength) . $suffix;
    }

    public function truncateWithWordBoundary(string $text, int $maxLength, string $suffix = '...'): string
    {
        if ($maxLength < 0) {
            throw new \InvalidArgumentException('Max length cannot be negative');
        }

        if ($maxLength === 0) {
            return '';
        }

        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $availableLength = $maxLength - strlen($suffix);

        if ($availableLength <= 0) {
            return substr($suffix, 0, $maxLength);
        }

        $truncated = substr($text, 0, $availableLength);
        $lastSpace = strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > $availableLength * 0.8) {
            $truncated = substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated) . $suffix;
    }

    public function truncateAtStart(string $text, int $maxLength, string $prefix = '...'): string
    {
        if ($maxLength < 0) {
            throw new \InvalidArgumentException('Max length cannot be negative');
        }

        if ($maxLength === 0) {
            return '';
        }

        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $availableLength = $maxLength - strlen($prefix);

        if ($availableLength <= 0) {
            return substr($prefix, 0, $maxLength);
        }

        return $prefix . substr($text, -$availableLength);
    }

    public function truncateMiddle(string $text, int $maxLength, string $ellipsis = '...'): string
    {
        if ($maxLength < 0) {
            throw new \InvalidArgumentException('Max length cannot be negative');
        }

        if ($maxLength === 0) {
            return '';
        }

        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $ellipsisLength = strlen($ellipsis);

        if ($ellipsisLength >= $maxLength) {
            return substr($ellipsis, 0, $maxLength);
        }

        $availableForContent = $maxLength - $ellipsisLength;
        $startLength = (int) floor($availableForContent * 0.6);
        $endLength = (int) floor($availableForContent * 0.4);

        return substr($text, 0, $startLength) . $ellipsis . substr($text, -$endLength);
    }

    public function truncateLines(string $text, int $maxLines, string $suffix = '...'): string
    {
        $lines = explode("\n", $text);

        if (count($lines) <= $maxLines) {
            return $text;
        }

        $truncatedLines = array_slice($lines, 0, $maxLines);

        return implode("\n", $truncatedLines) . $suffix;
    }

    public function truncateByCharacters(string $text, int $maxChars, string $suffix = '...'): string
    {
        return $this->truncate($text, $maxChars, $suffix);
    }

    public function truncateHtml(string $text, int $maxLength, string $suffix = '...'): string
    {
        if (strlen(strip_tags($text)) <= $maxLength) {
            return $text;
        }

        $truncated = $this->truncateWithWordBoundary($text, $maxLength, $suffix);

        $openTags = [];
        preg_match_all('/<([a-z]+)[^>]*>/i', $text, $matches);

        foreach ($matches[1] as $tag) {
            $openTags[] = strtolower($tag);
        }

        $closeTags = [];
        preg_match_all('/<\/([a-z]+)>/i', $text, $matches);

        foreach ($matches[1] as $tag) {
            $closeTags[] = strtolower($tag);
        }

        foreach (array_reverse($openTags) as $tag) {
            if (!in_array($tag, $closeTags, true)) {
                $truncated .= '</' . $tag . '>';
            }
        }

        return $truncated;
    }

    public function truncateSentence(string $text, int $maxLength, string $suffix = '...'): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $availableLength = $maxLength - strlen($suffix);

        if ($availableLength <= 0) {
            return substr($suffix, 0, $maxLength);
        }

        $truncated = substr($text, 0, $availableLength);
        $lastPeriod = strrpos($truncated, '.');

        if ($lastPeriod !== false && $lastPeriod > $availableLength * 0.7) {
            $truncated = substr($truncated, 0, $lastPeriod + 1);
        }

        return rtrim($truncated) . $suffix;
    }

    public function truncateParagraph(string $text, int $maxLength, string $suffix = '...'): string
    {
        $paragraphs = preg_split('/\n\n+/', $text);
        $result = '';
        $currentLength = 0;

        foreach ($paragraphs as $paragraph) {
            if ($currentLength + strlen($paragraph) + 2 <= $maxLength) {
                $result .= $paragraph . "\n\n";
                $currentLength += strlen($paragraph) + 2;
            } else {
                if ($result === '') {
                    return $this->truncateWithWordBoundary($paragraph, $maxLength, $suffix);
                }

                break;
            }
        }

        return rtrim($result) . $suffix;
    }

    public function truncateToFit(string $text, int $maxLength, callable $validator, string $suffix = '...'): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = $this->truncate($text, $maxLength, $suffix);

        while (strlen($truncated) > 0 && !$validator($truncated)) {
            $maxLength--;
            $truncated = $this->truncate($text, $maxLength, $suffix);
        }

        return $truncated;
    }

    public function getEllipsisPosition(string $original, string $truncated): int
    {
        $originalLength = strlen($original);
        $truncatedLength = strlen($truncated);

        if ($originalLength <= $truncatedLength) {
            return -1;
        }

        $suffixPosition = $truncatedLength - 3;

        return $suffixPosition >= 0 ? $suffixPosition : 0;
    }

    public function isTruncated(string $original, string $processed): bool
    {
        return strlen($original) > strlen($processed);
    }

    public function calculateTruncationRatio(string $original, string $processed): float
    {
        $originalLength = strlen($original);

        if ($originalLength === 0) {
            return 0.0;
        }

        return (strlen($original) - strlen($processed)) / $originalLength;
    }
}
