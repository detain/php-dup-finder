<?php

declare(strict_types=1);

namespace Blog\Url\Sanitize;

use Transliterator;

final class PostSlugBuilder
{
    private Transliterator $transliterator;

    public function __construct()
    {
        $t = Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        if ($t === null) {
            throw new \RuntimeException('ICU Transliterator unavailable');
        }
        $this->transliterator = $t;
    }

    public function build(string $title): string
    {
        $latin = $this->transliterator->transliterate(trim($title));
        if ($latin === false) {
            $latin = $title;
        }

        $chars = mb_str_split($latin);
        $out = '';
        $lastWasDash = false;

        foreach ($chars as $c) {
            if (preg_match('/[a-z0-9]/', $c) === 1) {
                $out .= $c;
                $lastWasDash = false;
            } elseif (!$lastWasDash && $out !== '') {
                $out .= '-';
                $lastWasDash = true;
            }
        }

        $out = rtrim($out, '-');

        if ($out === '') {
            return 'untitled';
        }

        return strlen($out) > 80
            ? rtrim(substr($out, 0, 80), '-')
            : $out;
    }
}
