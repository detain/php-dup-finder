<?php

declare(strict_types=1);

namespace Crm\Contacts\Extract;

final class RegexEmailExtractor
{
    private const EMAIL_RE = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    public function firstEmail(string $text): ?string
    {
        if (preg_match(self::EMAIL_RE, $text, $matches) !== 1) {
            return null;
        }

        $candidate = $matches[0];

        if (filter_var($candidate, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return strtolower($candidate);
    }
}
