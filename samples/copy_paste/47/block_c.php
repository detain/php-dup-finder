<?php

declare(strict_types=1);

namespace App\Text;

final class ContentShortener
{
    public function shorten(string $content, int $maxLen, string $trailer = '...'): string
    {
        if ($maxLen < 0) {
            throw new \InvalidArgumentException('Max length must be non-negative');
        }

        if ($maxLen === 0) {
            return '';
        }

        if (strlen($content) <= $maxLen) {
            return $content;
        }

        $headroom = $maxLen - strlen($trailer);

        if ($headroom <= 0) {
            return substr($trailer, 0, $maxLen);
        }

        return substr($content, 0, $headroom) . $trailer;
    }

    public function shortenWordBound(string $content, int $maxLen, string $trailer = '...'): string
    {
        if ($maxLen < 0) {
            throw new \InvalidArgumentException('Max length must be non-negative');
        }

        if ($maxLen === 0) {
            return '';
        }

        if (strlen($content) <= $maxLen) {
            return $content;
        }

        $headroom = $maxLen - strlen($trailer);

        if ($headroom <= 0) {
            return substr($trailer, 0, $maxLen);
        }

        $prefix = substr($content, 0, $headroom);
        $lastSpace = strrpos($prefix, ' ');

        if ($lastSpace !== false && $lastSpace > $headroom * 0.8) {
            $prefix = substr($prefix, 0, $lastSpace);
        }

        return rtrim($prefix) . $trailer;
    }

    public function shortenFront(string $content, int $maxLen, string $trailer = '...'): string
    {
        if ($maxLen < 0) {
            throw new \InvalidArgumentException('Max length must be non-negative');
        }

        if ($maxLen === 0) {
            return '';
        }

        if (strlen($content) <= $maxLen) {
            return $content;
        }

        $headroom = $maxLen - strlen($trailer);

        if ($headroom <= 0) {
            return substr($trailer, 0, $maxLen);
        }

        return $trailer . substr($content, -$headroom);
    }

    public function shortenMiddle(string $content, int $maxLen, string $ellipsis = '...'): string
    {
        if ($maxLen < 0) {
            throw new \InvalidArgumentException('Max length must be non-negative');
        }

        if ($maxLen === 0) {
            return '';
        }

        if (strlen($content) <= $maxLen) {
            return $content;
        }

        $eLen = strlen($ellipsis);

        if ($eLen >= $maxLen) {
            return substr($ellipsis, 0, $maxLen);
        }

        $space = $maxLen - $eLen;
        $front = (int) floor($space * 0.6);
        $back = (int) floor($space * 0.4);

        return substr($content, 0, $front) . $ellipsis . substr($content, -$back);
    }

    public function shortenLines(string $content, int $maxLines, string $trailer = '...'): string
    {
        $lines = explode("\n", $content);

        if (count($lines) <= $maxLines) {
            return $content;
        }

        return implode("\n", array_slice($lines, 0, $maxLines)) . $trailer;
    }

    public function shortenChars(string $content, int $charLimit, string $trailer = '...'): string
    {
        return $this->shorten($content, $charLimit, $trailer);
    }

    public function shortenHtml(string $content, int $maxLen, string $trailer = '...'): string
    {
        if (strlen(strip_tags($content)) <= $maxLen) {
            return $content;
        }

        $result = $this->shortenWordBound($content, $maxLen, $trailer);

        $opened = [];
        preg_match_all('/<([a-z]+)[^>]*>/i', $content, $m);

        foreach ($m[1] as $tag) {
            $opened[] = strtolower($tag);
        }

        $closed = [];
        preg_match_all('/<\/([a-z]+)>/i', $content, $m);

        foreach ($m[1] as $tag) {
            $closed[] = strtolower($tag);
        }

        foreach (array_reverse($opened) as $tag) {
            if (!in_array($tag, $closed, true)) {
                $result .= '</' . $tag . '>';
            }
        }

        return $result;
    }

    public function shortenSentences(string $content, int $maxLen, string $trailer = '...'): string
    {
        if (strlen($content) <= $maxLen) {
            return $content;
        }

        $headroom = $maxLen - strlen($trailer);

        if ($headroom <= 0) {
            return substr($trailer, 0, $maxLen);
        }

        $prefix = substr($content, 0, $headroom);
        $finalPeriod = strrpos($prefix, '.');

        if ($finalPeriod !== false && $finalPeriod > $headroom * 0.7) {
            $prefix = substr($prefix, 0, $finalPeriod + 1);
        }

        return rtrim($prefix) . $trailer;
    }

    public function shortenParagraph(string $content, int $maxLen, string $trailer = '...'): string
    {
        $chunks = preg_split('/\n\n+/', $content);
        $output = '';
        $len = 0;

        foreach ($chunks as $chunk) {
            if ($len + strlen($chunk) + 2 <= $maxLen) {
                $output .= $chunk . "\n\n";
                $len += strlen($chunk) + 2;
            } else {
                if ($output === '') {
                    return $this->shortenWordBound($chunk, $maxLen, $trailer);
                }

                break;
            }
        }

        return rtrim($output) . $trailer;
    }

    public function shortenWithCheck(string $content, int $maxLen, callable $isValid, string $trailer = '...'): string
    {
        if (strlen($content) <= $maxLen) {
            return $content;
        }

        $result = $this->shorten($content, $maxLen, $trailer);

        while (strlen($result) > 0 && !$isValid($result)) {
            $maxLen--;
            $result = $this->shorten($content, $maxLen, $trailer);
        }

        return $result;
    }

    public function locateEllipsis(string $orig, string $short): int
    {
        $oLen = strlen($orig);
        $sLen = strlen($short);

        if ($oLen <= $sLen) {
            return -1;
        }

        return $sLen - 3;
    }

    public function isShortened(string $orig, string $short): bool
    {
        return strlen($orig) > strlen($short);
    }

    public function truncationPercent(string $orig, string $short): float
    {
        $oLen = strlen($orig);

        if ($oLen === 0) {
            return 0.0;
        }

        return ((strlen($orig) - strlen($short)) / $oLen) * 100;
    }
}
