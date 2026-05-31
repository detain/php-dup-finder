<?php

declare(strict_types=1);

namespace Docs\Slug;

final class DocumentSlugMaker
{
    public function make(string $title): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($title));
        if ($ascii === false) {
            $ascii = $title;
        }

        $ascii = strtolower($ascii);
        $tokens = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            $tokens = [];
        }

        $slug = implode('-', $tokens);

        if ($slug === '') {
            return 'untitled';
        }

        if (strlen($slug) <= 80) {
            return $slug;
        }

        $truncated = substr($slug, 0, 80);
        $lastDash = strrpos($truncated, '-');

        if ($lastDash !== false && $lastDash > 0) {
            return substr($truncated, 0, $lastDash);
        }

        return $truncated;
    }
}
