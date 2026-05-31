<?php

declare(strict_types=1);

namespace App\Slug;

use Transliterator;

final class CanonicalSlugger
{
    private const FALLBACK = 'untitled';
    private const MAX_LENGTH = 80;

    private ?Transliterator $transliterator;

    public function __construct()
    {
        $this->transliterator = Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
    }

    public function slug(string $input): string
    {
        $ascii = $this->toAsciiLower(trim($input));

        $parts = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $slug = implode('-', $parts);

        if ($slug === '') {
            return self::FALLBACK;
        }

        if (strlen($slug) > self::MAX_LENGTH) {
            $slug = substr($slug, 0, self::MAX_LENGTH);
            $slug = rtrim($slug, '-');
            if ($slug === '') {
                return self::FALLBACK;
            }
        }

        return $slug;
    }

    private function toAsciiLower(string $text): string
    {
        if ($this->transliterator !== null) {
            $latin = $this->transliterator->transliterate($text);
            if ($latin !== false) {
                return $latin;
            }
        }

        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return strtolower($converted !== false ? $converted : $text);
    }
}
