<?php
declare(strict_types=1);

namespace ChatGuard\Filters;

final class LogScrubber
{
    public function scrub(string $logLine): string
    {
        $piiRegex = '/(?<!\d)(?:(?:\d{3}-\d{2}-\d{4})|(?:\d{9})(?!\d)|'
                  . '(?:4\d{3}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
                  . '(?:5[1-5]\d{2}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
                  . '(?:3[47]\d{2}[\s-]?\d{6}[\s-]?\d{5})|'
                  . '(?:6011[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}))(?!\d)/';

        return preg_replace($piiRegex, '[REDACTED]', $logLine) ?? $logLine;
    }

    public function containsPii(string $logLine): bool
    {
        $piiRegex = '/(?<!\d)(?:(?:\d{3}-\d{2}-\d{4})|(?:\d{9})(?!\d)|'
                  . '(?:4\d{3}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
                  . '(?:5[1-5]\d{2}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
                  . '(?:3[47]\d{2}[\s-]?\d{6}[\s-]?\d{5})|'
                  . '(?:6011[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}))(?!\d)/';

        return preg_match($piiRegex, $logLine) === 1;
    }
}
