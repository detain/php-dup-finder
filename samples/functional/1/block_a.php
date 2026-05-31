<?php
declare(strict_types=1);

namespace Acme\Blog\Slugs;

final class RegexSlugger
{
    private const MAX_LENGTH = 120;
    private const STOPWORDS = ['the', 'a', 'an', 'of', 'and', 'or'];

    public function fromTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('title is empty');
        }
        $lowered = mb_strtolower($title, 'UTF-8');
        $ascii   = $this->stripDiacritics($lowered);
        $words   = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $filtered = [];
        foreach ($words as $word) {
            if (in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $filtered[] = $word;
        }
        if ($filtered === []) {
            $filtered = $words;
        }
        $slug = implode('-', $filtered);
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');
        if (strlen($slug) > self::MAX_LENGTH) {
            $slug = substr($slug, 0, self::MAX_LENGTH);
            $slug = rtrim($slug, '-');
        }
        return $slug !== '' ? $slug : 'untitled';
    }

    private function stripDiacritics(string $input): string
    {
        $map = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe',
        ];
        return strtr($input, $map);
    }
}
