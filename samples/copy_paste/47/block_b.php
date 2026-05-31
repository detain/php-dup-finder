<?php

declare(strict_types=1);

namespace App\Display;

final class TextCutter
{
    public function cut(string $input, int $limit, string $ellipsis = '...'): string
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit cannot be negative');
        }

        if ($limit === 0) {
            return '';
        }

        if (strlen($input) <= $limit) {
            return $input;
        }

        $budget = $limit - strlen($ellipsis);

        if ($budget <= 0) {
            return substr($ellipsis, 0, $limit);
        }

        return substr($input, 0, $budget) . $ellipsis;
    }

    public function cutAtWord(string $input, int $limit, string $ellipsis = '...'): string
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit cannot be negative');
        }

        if ($limit === 0) {
            return '';
        }

        if (strlen($input) <= $limit) {
            return $input;
        }

        $budget = $limit - strlen($ellipsis);

        if ($budget <= 0) {
            return substr($ellipsis, 0, $limit);
        }

        $segment = substr($input, 0, $budget);
        $lastSpace = strrpos($segment, ' ');

        if ($lastSpace !== false && $lastSpace > $budget * 0.8) {
            $segment = substr($segment, 0, $lastSpace);
        }

        return rtrim($segment) . $ellipsis;
    }

    public function cutFromStart(string $input, int $limit, string $ellipsis = '...'): string
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit cannot be negative');
        }

        if ($limit === 0) {
            return '';
        }

        if (strlen($input) <= $limit) {
            return $input;
        }

        $budget = $limit - strlen($ellipsis);

        if ($budget <= 0) {
            return substr($ellipsis, 0, $limit);
        }

        return $ellipsis . substr($input, -$budget);
    }

    public function cutCenter(string $input, int $limit, string $ellipsis = '...'): string
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit cannot be negative');
        }

        if ($limit === 0) {
            return '';
        }

        if (strlen($input) <= $limit) {
            return $input;
        }

        $ellipsisLen = strlen($ellipsis);

        if ($ellipsisLen >= $limit) {
            return substr($ellipsis, 0, $limit);
        }

        $remaining = $limit - $ellipsisLen;
        $prefixLen = (int) floor($remaining * 0.6);
        $suffixLen = (int) floor($remaining * 0.4);

        return substr($input, 0, $prefixLen) . $ellipsis . substr($input, -$suffixLen);
    }

    public function cutLines(string $input, int $maxLines, string $ellipsis = '...'): string
    {
        $lineArray = explode("\n", $input);

        if (count($lineArray) <= $maxLines) {
            return $input;
        }

        return implode("\n", array_slice($lineArray, 0, $maxLines)) . $ellipsis;
    }

    public function cutByCharCount(string $input, int $charLimit, string $ellipsis = '...'): string
    {
        return $this->cut($input, $charLimit, $ellipsis);
    }

    public function cutHtml(string $input, int $limit, string $ellipsis = '...'): string
    {
        if (strlen(strip_tags($input)) <= $limit) {
            return $input;
        }

        $cut = $this->cutAtWord($input, $limit, $ellipsis);

        $openTags = [];
        preg_match_all('/<([a-z]+)[^>]*>/i', $input, $m);

        foreach ($m[1] as $tag) {
            $openTags[] = strtolower($tag);
        }

        $closeTags = [];
        preg_match_all('/<\/([a-z]+)>/i', $input, $m);

        foreach ($m[1] as $tag) {
            $closeTags[] = strtolower($tag);
        }

        foreach (array_reverse($openTags) as $tag) {
            if (!in_array($tag, $closeTags, true)) {
                $cut .= '</' . $tag . '>';
            }
        }

        return $cut;
    }

    public function cutSentence(string $input, int $limit, string $ellipsis = '...'): string
    {
        if (strlen($input) <= $limit) {
            return $input;
        }

        $budget = $limit - strlen($ellipsis);

        if ($budget <= 0) {
            return substr($ellipsis, 0, $limit);
        }

        $segment = substr($input, 0, $budget);
        $lastDot = strrpos($segment, '.');

        if ($lastDot !== false && $lastDot > $budget * 0.7) {
            $segment = substr($segment, 0, $lastDot + 1);
        }

        return rtrim($segment) . $ellipsis;
    }

    public function cutParagraph(string $input, int $limit, string $ellipsis = '...'): string
    {
        $paragraphs = preg_split('/\n\n+/', $input);
        $output = '';
        $length = 0;

        foreach ($paragraphs as $p) {
            if ($length + strlen($p) + 2 <= $limit) {
                $output .= $p . "\n\n";
                $length += strlen($p) + 2;
            } else {
                if ($output === '') {
                    return $this->cutAtWord($p, $limit, $ellipsis);
                }

                break;
            }
        }

        return rtrim($output) . $ellipsis;
    }

    public function cutWithValidation(string $input, int $limit, callable $checker, string $ellipsis = '...'): string
    {
        if (strlen($input) <= $limit) {
            return $input;
        }

        $result = $this->cut($input, $limit, $ellipsis);

        while (strlen($result) > 0 && !$checker($result)) {
            $limit--;
            $result = $this->cut($input, $limit, $ellipsis);
        }

        return $result;
    }

    public function findEllipsisIndex(string $original, string $modified): int
    {
        $origLen = strlen($original);
        $modLen = strlen($modified);

        if ($origLen <= $modLen) {
            return -1;
        }

        $pos = $modLen - 3;

        return $pos >= 0 ? $pos : 0;
    }

    public function wasModified(string $original, string $modified): bool
    {
        return strlen($original) > strlen($modified);
    }

    public function compressionRatio(string $original, string $modified): float
    {
        $origLen = strlen($original);

        if ($origLen === 0) {
            return 0.0;
        }

        return (strlen($original) - strlen($modified)) / $origLen;
    }
}
