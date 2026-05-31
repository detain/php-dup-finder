<?php

declare(strict_types=1);

namespace Sales\Lead\Parse;

final class TokenizingEmailFinder
{
    public function scan(string $text): ?string
    {
        $tokens = preg_split('/[\s,;()<>\[\]"]+/', $text) ?: [];

        foreach ($tokens as $token) {
            $token = trim($token, ".,;:!?'\"");

            if ($token === '' || strpos($token, '@') === false) {
                continue;
            }

            if (filter_var($token, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            return strtolower($token);
        }

        return null;
    }
}
