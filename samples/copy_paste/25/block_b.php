<?php

declare(strict_types=1);

namespace App\Routing;

use App\Exceptions\UrlSlugException;

final class UrlSlugGenerator
{
    private const DEFAULT_MAX = 100;
    private const DEFAULT_MIN = 2;
    private const SEPARATORS = ['-', '_'];

    public function generate(string $text): string
    {
        $this->guardEmptyInput($text);

        $slug = $this->convertToAscii($text);
        $slug = $this->toLowerCase($slug);
        $slug = $this->stripNonAlphanumeric($slug);
        $slug = $this->normalizeSeparators($slug);
        $slug = $this->trimTrailingSeparators($slug);
        $slug = $this->capLength($slug);

        return $slug;
    }

    public function generateUnicode(string $text): string
    {
        $this->guardEmptyInput($text);

        $slug = $this->normalizeUnicodeChars($text);
        $slug = $this->convertToAscii($slug);
        $slug = $this->toLowerCase($slug);
        $slug = $this->stripNonAlphanumeric($slug);
        $slug = $this->normalizeSeparators($slug);
        $slug = $this->trimTrailingSeparators($slug);
        $slug = $this->capLength($slug);

        return $slug;
    }

    public function generatePrefixed(string $text, string $prefix): string
    {
        $slug = $this->generate($text);

        return $prefix . '-' . $slug;
    }

    public function generateAuthorSlug(string $authorName): string
    {
        $slug = $this->generate($authorName);
        $slug = $this->capLength($slug, 3, 60);

        return $slug;
    }

    public function generateArticleSlug(string $headline): string
    {
        $slug = $this->generate($headline);
        $slug = $this->capLength($slug, 3, self::DEFAULT_MAX);

        return $slug;
    }

    public function generatePageSlug(string $pageTitle): string
    {
        $slug = $this->generate($pageTitle);

        return $slug;
    }

    public function generateMediaSlug(string $filename): string
    {
        $slug = $this->generate($filename);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        if (!empty($extension)) {
            return $slug . '.' . $this->generate($extension);
        }

        return $slug;
    }

    public function generateNested(array $pathParts): string
    {
        $slugs = array_map(fn($part) => $this->generate($part), $pathParts);

        return implode('/', $slugs);
    }

    public function generateWithSuffix(string $text, string $suffix): string
    {
        $slug = $this->generate($text);

        return $slug . '-' . $this->sanitizeSuffix($suffix);
    }

    public function generateUnique(string $text, array $occupied = []): string
    {
        $base = $this->generate($text);
        $candidate = $base;
        $suffix = 1;

        while (in_array($candidate, $occupied, true)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function guardEmptyInput(string $input): void
    {
        if (trim($input) === '') {
            throw new UrlSlugException('Cannot create slug from empty string');
        }
    }

    private function convertToAscii(string $text): string
    {
        $charMap = [
            'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A',
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
            'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
            'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
            'Ñ'=>'n','ñ'=>'n','Ç'=>'C','ç'=>'c',
            'ß'=>'ss','ÿ'=>'ye','œ'=>'oe','æ'=>'ae',
        ];

        return strtr($text, $charMap);
    }

    private function toLowerCase(string $text): string
    {
        return mb_strtolower($text, 'UTF-8');
    }

    private function stripNonAlphanumeric(string $text): string
    {
        $pattern = '/[^a-z0-9' . preg_quote('-', '/') . ']/';

        return preg_replace($pattern, '-', $text);
    }

    private function normalizeSeparators(string $text): string
    {
        $text = preg_replace('/-+/', '-', $text);

        return str_replace(['_', ' '], '-', $text);
    }

    private function trimTrailingSeparators(string $text): string
    {
        return trim($text, '-');
    }

    private function capLength(string $text, int $min = self::DEFAULT_MIN, int $max = self::DEFAULT_MAX): string
    {
        if (strlen($text) < $min) {
            throw new UrlSlugException("Slug '{$text}' is below minimum length of {$min}");
        }

        if (strlen($text) > $max) {
            return substr($text, 0, $max);
        }

        return $text;
    }

    private function sanitizeSuffix(string $suffix): string
    {
        return $this->generate($suffix);
    }
}
