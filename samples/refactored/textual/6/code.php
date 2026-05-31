<?php
declare(strict_types=1);

namespace ChatGuard\Filters;

final class PiiPatterns
{
    public const COMBINED = '/(?<!\d)(?:(?:\d{3}-\d{2}-\d{4})|(?:\d{9})(?!\d)|'
        . '(?:4\d{3}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
        . '(?:5[1-5]\d{2}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
        . '(?:3[47]\d{2}[\s-]?\d{6}[\s-]?\d{5})|'
        . '(?:6011[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}))(?!\d)/';

    public static function matches(string $haystack): bool
    {
        return preg_match(self::COMBINED, $haystack) === 1;
    }

    public static function redact(string $haystack, string $replacement = '[REDACTED]'): string
    {
        return preg_replace(self::COMBINED, $replacement, $haystack) ?? $haystack;
    }
}

final class MessageBodyValidator
{
    public function validate(string $messageBody): bool
    {
        return trim($messageBody) !== ''
            && mb_strlen($messageBody) <= 4000
            && ! PiiPatterns::matches($messageBody);
    }
}

final class LogScrubber
{
    public function scrub(string $logLine): string
    {
        return PiiPatterns::redact($logLine);
    }
}
