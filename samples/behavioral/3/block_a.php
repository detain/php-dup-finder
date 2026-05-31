<?php

declare(strict_types=1);

namespace Cms\Routing\Slugs;

final class ArticleSlugger
{
    public function slug(string $title): string
    {
        $title = trim($title);
        $title = mb_strtolower($title, 'UTF-8');

        $title = preg_replace('/[àáâãäå]/u', 'a', $title) ?? $title;
        $title = preg_replace('/[èéêë]/u', 'e', $title) ?? $title;
        $title = preg_replace('/[ìíîï]/u', 'i', $title) ?? $title;
        $title = preg_replace('/[òóôõö]/u', 'o', $title) ?? $title;
        $title = preg_replace('/[ùúûü]/u', 'u', $title) ?? $title;
        $title = preg_replace('/[ñ]/u', 'n', $title) ?? $title;
        $title = preg_replace('/[ç]/u', 'c', $title) ?? $title;

        $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? $title;
        $title = preg_replace('/-+/', '-', $title) ?? $title;
        $title = trim($title, '-');

        if ($title === '') {
            return 'untitled';
        }

        if (strlen($title) > 80) {
            $title = substr($title, 0, 80);
            $title = rtrim($title, '-');
        }

        return $title;
    }
}
