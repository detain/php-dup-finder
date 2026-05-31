<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Exceptions\SlugException;

final class PathSlugCreator
{
    private const MAX_CHAR = 100;
    private const MIN_CHAR = 2;
    private const DELIMITER = '-';

    public function fromTitle(string $title): string
    {
        $this->ensureNotBlank($title);

        $slug = $this->latinize($title);
        $slug = $this->toAsciiLower($slug);
        $slug = $this->replaceNonWordChars($slug);
        $slug = $this->mergeRepeatedDelimiters($slug);
        $slug = $this->stripDelimiterEnds($slug);
        $slug = $this->constrainLength($slug);

        return $slug;
    }

    public function fromUnicode(string $title): string
    {
        $this->ensureNotBlank($title);

        $slug = $this->unicodeNormalize($title);
        $slug = $this->latinize($slug);
        $slug = $this->toAsciiLower($slug);
        $slug = $this->replaceNonWordChars($slug);
        $slug = $this->mergeRepeatedDelimiters($slug);
        $slug = $this->stripDelimiterEnds($slug);
        $slug = $this->constrainLength($slug);

        return $slug;
    }

    public function fromTitleWithPrefix(string $title, string $prefix): string
    {
        $slug = $this->fromTitle($title);

        return $prefix . self::DELIMITER . $slug;
    }

    public function fromAuthorName(string $name): string
    {
        $slug = $this->fromTitle($name);
        $slug = $this->constrainLength($slug, 3, 60);

        return $slug;
    }

    public function fromPostTitle(string $postTitle): string
    {
        $slug = $this->fromTitle($postTitle);

        return $slug;
    }

    public function fromCollectionName(string $name): string
    {
        $slug = $this->fromTitle($name);
        $slug = $this->constrainLength($slug, 2, 80);

        return $slug;
    }

    public function fromFilename(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $slug = $this->fromTitle($name);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        if ($ext) {
            return $slug . '.' . strtolower($ext);
        }

        return $slug;
    }

    public function fromPath(array $segments): string
    {
        $parts = array_map(fn($seg) => $this->fromTitle($seg), $segments);

        return implode('/', $parts);
    }

    public function fromTitleWithSuffix(string $title, string $suffix): string
    {
        $slug = $this->fromTitle($title);
        $suffixSlug = $this->sanitize($suffix);

        return $slug . self::DELIMITER . $suffixSlug;
    }

    public function ensureUnique(string $title, array $taken = []): string
    {
        $base = $this->fromTitle($title);
        $candidate = $base;
        $index = 1;

        while (in_array($candidate, $taken, true)) {
            $candidate = $base . self::DELIMITER . $index;
            $index++;
        }

        return $candidate;
    }

    private function ensureNotBlank(string $input): void
    {
        if (trim($input) === '') {
            throw new SlugException('Cannot create slug from blank input');
        }
    }

    private function unicodeNormalize(string $text): string
    {
        if (function_exists('normalizer_normalize')) {
            return normalizer_normalize($text, normalizer::FORM_C) ?: $text;
        }

        return $text;
    }

    private function latinize(string $text): string
    {
        $transliterationTable = [
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
            'Ñ'=>'n','ñ'=>'n','Ç'=>'c','ç'=>'c',
        ];

        return strtr($text, $transliterationTable);
    }

    private function toAsciiLower(string $text): string
    {
        return mb_strtolower($text, 'UTF-8');
    }

    private function replaceNonWordChars(string $text): string
    {
        return preg_replace('/[^\p{L}\p{N}\-_]/u', self::DELIMITER, $text);
    }

    private function mergeRepeatedDelimiters(string $text): string
    {
        return preg_replace('/' . preg_quote(self::DELIMITER, '/') . '{2,}/', self::DELIMITER, $text);
    }

    private function stripDelimiterEnds(string $text): string
    {
        return trim($text, self::DELIMITER);
    }

    private function constrainLength(string $text, int $min = self::MIN_CHAR, int $max = self::MAX_CHAR): string
    {
        if (strlen($text) < $min) {
            throw new SlugException("Slug '{$text}' falls below minimum length of {$min}");
        }

        return substr($text, 0, $max);
    }

    private function sanitize(string $suffix): string
    {
        return $this->fromTitle($suffix);
    }
}
