<?php
declare(strict_types=1);

namespace Acme\Forum\Sanitize;

final class RegexTidySanitizer
{
    /** @var list<string> */
    private array $allowedTags = ['a', 'p', 'strong', 'em', 'ul', 'ol', 'li', 'br', 'code', 'pre', 'blockquote'];

    public function scrub(string $input): string
    {
        if (trim($input) === '') {
            return '';
        }
        $stripped = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $input) ?? $input;
        $stripped = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $stripped) ?? $stripped;
        $stripped = preg_replace('#on\w+\s*=\s*"[^"]*"#i', '', $stripped) ?? $stripped;
        $stripped = preg_replace("#on\w+\s*=\s*'[^']*'#i", '', $stripped) ?? $stripped;
        $stripped = preg_replace('#javascript:#i', '', $stripped) ?? $stripped;
        $allowList = '<' . implode('><', $this->allowedTags) . '>';
        $stripped  = strip_tags($stripped, $allowList);
        $stripped  = preg_replace_callback(
            '#<a\b([^>]*)>#i',
            static function (array $m): string {
                $attrs = $m[1];
                if (preg_match('#href\s*=\s*["\']?([^"\'\s>]+)#i', $attrs, $h)) {
                    if (preg_match('#^(https?://|/|mailto:)#i', $h[1])) {
                        return '<a href="' . htmlspecialchars($h[1], ENT_QUOTES) . '">';
                    }
                }
                return '<a>';
            },
            $stripped
        ) ?? $stripped;
        if (function_exists('tidy_repair_string')) {
            $repaired = tidy_repair_string($stripped, ['show-body-only' => true, 'wrap' => 0], 'utf8');
            if (is_string($repaired)) {
                $stripped = $repaired;
            }
        }
        return trim($stripped);
    }
}
