<?php

declare(strict_types=1);

namespace App\Content\Slugs;

use App\Exceptions\SlugGenerationException;

final class SlugFactory
{
    private const MAX_LENGTH = 100;
    private const MIN_LENGTH = 2;
    private const WORD_SEPARATORS = [' ', '_', '-', '.', '/'];
    private const COLLAPSE_PATTERN = '/-{2,}/';

    public function createSlug(string $title): string
    {
        $this->validateInput($title);

        $slug = $this->transliterate($title);
        $slug = $this->lowercase($slug);
        $slug = $this->removeSpecialCharacters($slug);
        $slug = $this->collapseSeparators($slug);
        $slug = $this->trimSeparators($slug);
        $slug = $this->enforceLength($slug);

        return $slug;
    }

    public function createSlugFromUnicode(string $title): string
    {
        $this->validateInput($title);

        $slug = $this->normalizeUnicode($title);
        $slug = $this->transliterate($slug);
        $slug = $this->lowercase($slug);
        $slug = $this->removeSpecialCharacters($slug);
        $slug = $this->collapseSeparators($slug);
        $slug = $this->trimSeparators($slug);
        $slug = $this->enforceLength($slug);

        return $slug;
    }

    public function createSlugWithPrefix(string $title, string $prefix): string
    {
        $slug = $this->createSlug($title);

        return $prefix . '-' . $slug;
    }

    public function createCategorySlug(string $category): string
    {
        $slug = $this->createSlug($category);
        $slug = $this->prefixWith('category', $slug);

        return $slug;
    }

    public function createTagSlug(string $tag): string
    {
        $slug = $this->createSlug($tag);

        return $this->prefixWith('tag', $slug);
    }

    public function createProductSlug(string $productName, string $sku): string
    {
        $slug = $this->createSlug($productName);

        if (!empty($sku)) {
            $skuSlug = $this->createSlug($sku);
            $slug = $slug . '-' . $skuSlug;
        }

        return $slug;
    }

    public function createUserSlug(string $username): string
    {
        $slug = $this->createSlug($username);
        $slug = $this->lowercase($slug);
        $slug = $this->enforceLength($slug, 3, 50);

        return $slug;
    }

    public function createDateBasedSlug(string $title, \DateTimeInterface $date): string
    {
        $slug = $this->createSlug($title);
        $dateSlug = $date->format('Y-m-d');

        return $dateSlug . '-' . $slug;
    }

    public function createHierarchicalSlug(array $segments): string
    {
        $slugs = array_map(fn($segment) => $this->createSlug($segment), $segments);

        return implode('/', $slugs);
    }

    public function createUniqueSlug(string $title, array $existingSlugs = []): string
    {
        $baseSlug = $this->createSlug($title);
        $slug = $baseSlug;
        $counter = 1;

        while (in_array($slug, $existingSlugs, true)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function validateInput(string $input): void
    {
        if (empty(trim($input))) {
            throw new SlugGenerationException('Slug cannot be generated from empty input');
        }

        if (strlen($input) > 500) {
            throw new SlugGenerationException('Input string is too long for slug generation');
        }
    }

    private function transliterate(string $text): string
    {
        $transliterations = [
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ñ' => 'n', 'ñ' => 'n', 'Ç' => 'C', 'ç' => 'c',
            'ß' => 'ss', 'ÿ' => 'ye', 'œ' => 'oe', 'æ' => 'ae',
        ];

        return strtr($text, $transliterations);
    }

    private function normalizeUnicode(string $text): string
    {
        if (function_exists('normalizer_normalize')) {
            return normalizer_normalize($text, normalizer::FORM_C) ?: $text;
        }

        return $text;
    }

    private function lowercase(string $text): string
    {
        return mb_strtolower($text, 'UTF-8');
    }

    private function removeSpecialCharacters(string $text): string
    {
        return preg_replace('/[^a-z0-9' . implode('', array_map('preg_quote', self::WORD_SEPARATORS)) . ']/', '-', $text);
    }

    private function collapseSeparators(string $text): string
    {
        return preg_replace(self::COLLAPSE_PATTERN, '-', $text);
    }

    private function trimSeparators(string $text): string
    {
        return trim($text, '-');
    }

    private function enforceLength(string $slug, int $min = self::MIN_LENGTH, int $max = self::MAX_LENGTH): string
    {
        if (strlen($slug) < $min) {
            throw new SlugGenerationException("Generated slug is too short (min: {$min})");
        }

        if (strlen($slug) > $max) {
            return substr($slug, 0, $max);
        }

        return $slug;
    }

    private function prefixWith(string $prefix, string $slug): string
    {
        return $prefix . '-' . $slug;
    }
}
