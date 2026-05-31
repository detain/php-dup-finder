<?php
declare(strict_types=1);

namespace Acme\Blog\Slugs;

final class IconvSlugger
{
    private int $maxLength;

    public function __construct(int $maxLength = 120)
    {
        if ($maxLength < 1) {
            throw new \InvalidArgumentException('max length must be positive');
        }
        $this->maxLength = $maxLength;
    }

    public function slugify(string $heading): string
    {
        $cleaned = trim($heading);
        if ($cleaned === '') {
            throw new \InvalidArgumentException('heading required');
        }
        $previousLocale = setlocale(LC_CTYPE, '0');
        setlocale(LC_CTYPE, 'en_US.UTF-8');
        try {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cleaned);
            if ($transliterated === false) {
                $transliterated = $cleaned;
            }
        } finally {
            setlocale(LC_CTYPE, $previousLocale);
        }
        $lower    = strtolower($transliterated);
        $stripped = preg_replace('/[^a-z0-9\s\-]/', '', $lower) ?? $lower;
        $compact  = preg_replace('/[\s\-]+/', '-', $stripped) ?? $stripped;
        $trimmed  = trim($compact, '-');
        $stop     = ['the', 'a', 'an', 'of', 'and', 'or'];
        $parts    = explode('-', $trimmed);
        $keep     = array_values(array_filter($parts, static fn(string $p): bool => $p !== '' && !in_array($p, $stop, true)));
        if ($keep === []) {
            $keep = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
        }
        $slug = implode('-', $keep);
        if (strlen($slug) > $this->maxLength) {
            $slug = rtrim(substr($slug, 0, $this->maxLength), '-');
        }
        return $slug !== '' ? $slug : 'untitled';
    }
}
