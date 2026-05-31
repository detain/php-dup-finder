<?php
declare(strict_types=1);

namespace Acme\Blog\Slugs;

final class Slugger
{
    private const STOPWORDS = ['the', 'a', 'an', 'of', 'and', 'or'];

    public function __construct(private readonly int $maxLength = 120)
    {
        if ($maxLength < 1) {
            throw new \InvalidArgumentException('maxLength must be positive');
        }
    }

    public function slugify(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('title is empty');
        }
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        if ($ascii === false || $ascii === '') {
            $ascii = preg_replace('/[^\x20-\x7E]/', '', $title) ?? $title;
        }
        $lower   = strtolower($ascii);
        $tokens  = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept    = array_values(array_filter($tokens, static fn(string $w): bool => !in_array($w, self::STOPWORDS, true)));
        if ($kept === []) {
            $kept = $tokens;
        }
        $slug = implode('-', $kept);
        if (strlen($slug) > $this->maxLength) {
            $slug = rtrim(substr($slug, 0, $this->maxLength), '-');
        }
        return $slug !== '' ? $slug : 'untitled';
    }
}
