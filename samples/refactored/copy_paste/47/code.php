<?php

namespace App\Services\Content;

final class TruncationConfig
{
    public readonly string $ellipsis;
    public readonly bool $wordBoundary;
    public readonly bool $preserveHtml;

    public function __construct(
        string $ellipsis = '...',
        bool $wordBoundary = true,
        bool $preserveHtml = false
    ) {
        $this->ellipsis = $ellipsis;
        $this->wordBoundary = $wordBoundary;
        $this->preserveHtml = $preserveHtml;
    }
}

final class TruncationService
{
    private TruncationConfig $config;

    public function __construct(TruncationConfig $config)
    {
        $this->config = $config;
    }

    public function truncate(string $text, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $ellipsis = $this->config->ellipsis;
        $available = $maxLength - strlen($ellipsis);

        if ($available <= 0) {
            return substr($ellipsis, 0, $maxLength);
        }

        $truncated = substr($text, 0, $available);

        if ($this->config->wordBoundary) {
            $lastSpace = strrpos($truncated, ' ');

            if ($lastSpace !== false && $lastSpace > $available * 0.8) {
                $truncated = substr($truncated, 0, $lastSpace);
            }
        }

        return rtrim($truncated) . $ellipsis;
    }

    public function truncateMiddle(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $ellipsis = $this->config->ellipsis;
        $ellipsisLen = strlen($ellipsis);

        if ($ellipsisLen >= $maxLength) {
            return substr($ellipsis, 0, $maxLength);
        }

        $remaining = $maxLength - $ellipsisLen;
        $prefixLen = (int) floor($remaining * 0.6);
        $suffixLen = (int) floor($remaining * 0.4);

        return substr($text, 0, $prefixLen) . $ellipsis . substr($text, -$suffixLen);
    }

    public function truncateFront(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $ellipsis = $this->config->ellipsis;
        $available = $maxLength - strlen($ellipsis);

        if ($available <= 0) {
            return substr($ellipsis, 0, $maxLength);
        }

        return $ellipsis . substr($text, -$available);
    }
}
