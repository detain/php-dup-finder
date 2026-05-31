<?php

declare(strict_types=1);

namespace App\Helpers;

class StringHelper
{
    public static function truncate(string $text, int $length, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length - mb_strlen($suffix)) . $suffix;
    }

    public static function slugify(string $text): string
    {
        $text = mb_strtolower($text);

        $text = preg_replace('/[^a-z0-9]+/u', '-', $text);
        $text = trim($text, '-');

        return $text;
    }

    public static function camelToSnake(string $input): string
    {
        $output = preg_replace('/([a-z])([A-Z])/', '$1_$2', $input);
        return mb_strtolower($output);
    }

    public static function snakeToCamel(string $input, bool $capitalizeFirst = false): string
    {
        $result = str_replace(' ', '', ucwords(str_replace('_', ' ', $input)));

        if (!$capitalizeFirst) {
            $result = lcfirst($result);
        }

        return $result;
    }

    public static function capitalize(string $text): string
    {
        return mb_convert_case($text, MB_CASE_TITLE, 'UTF-8');
    }

    public static function random(int $length = 16): string
    {
        $bytes = random_bytes($length);
        return bin2hex($bytes);
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        return str_starts_with($haystack, $needle);
    }

    public static function endsWith(string $haystack, string $needle): bool
    {
        return str_ends_with($haystack, $needle);
    }

    public static function contains(string $haystack, string $needle): bool
    {
        return str_contains($haystack, $needle);
    }

    public static function limit(string $text, int $limit = 100): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit) . '...';
    }

    public static function words(string $text, int $limit = 100): string
    {
        $words = preg_split('/\s+/', $text);
        $count = count($words);

        if ($count <= $limit) {
            return $text;
        }

        return implode(' ', array_slice($words, 0, $limit)) . '...';
    }

    public static function stripTags(string $text): string
    {
        return strip_tags($text);
    }

    public static function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    public static function unescapeHtml(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    }

    public static function excerpt(string $text, string $phrase, int $radius = 100): string
    {
        $phrasePos = mb_stripos($text, $phrase);

        if ($phrasePos === false) {
            return self::limit($text, $radius * 2);
        }

        $startPos = max(0, $phrasePos - $radius);
        $endPos = min(mb_strlen($text), $phrasePos + mb_strlen($phrase) + $radius);

        $excerpt = mb_substr($text, $startPos, $endPos - $startPos);

        if ($startPos > 0) {
            $excerpt = '...' . $excerpt;
        }

        if ($endPos < mb_strlen($text)) {
            $excerpt = $excerpt . '...';
        }

        return $excerpt;
    }

    public static function isEmail(string $text): bool
    {
        return filter_var($text, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function isUrl(string $text): bool
    {
        return filter_var($text, FILTER_VALIDATE_URL) !== false;
    }

    public static function replaceAll(string $text, string $search, string $replace): string
    {
        return str_replace($search, $replace, $text);
    }

    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email);

        if (count($parts) !== 2) {
            return $email;
        }

        $name = $parts[0];
        $domain = $parts[1];

        $maskedName = self::mask($name);
        return $maskedName . '@' . $domain;
    }

    public static function mask(string $text, string $maskChar = '*', int $percent = 50): string
    {
        $length = mb_strlen($text);
        $maskCount = (int) ceil($length * $percent / 100);

        $result = '';
        for ($i = 0; $i < $length; $i++) {
            if ($i < $maskCount) {
                $result .= $maskChar;
            } else {
                $result .= $text[$i];
            }
        }

        return $result;
    }
}
