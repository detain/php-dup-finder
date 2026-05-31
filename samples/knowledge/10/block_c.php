<?php

declare(strict_types=1);

namespace App\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

final class RedactingLogger extends AbstractLogger
{
    public function __construct(private LoggerInterface $inner) {}

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $sensitiveFields = ['ssn', 'date_of_birth', 'phone', 'email', 'full_name', 'address_line'];

        $context = $this->scrub($context, $sensitiveFields);

        // Best-effort scrub of inline message tokens.
        $scrubbedMessage = (string) $message;
        foreach ($sensitiveFields as $field) {
            $pattern = '/' . preg_quote($field, '/') . '"\s*:\s*"[^"]*"/i';
            $scrubbedMessage = preg_replace($pattern, $field . '":"[REDACTED]"', $scrubbedMessage) ?? $scrubbedMessage;
        }

        $this->inner->log($level, $scrubbedMessage, $context);
    }

    private function scrub(array $context, array $sensitiveFields): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = $this->scrub($value, $sensitiveFields);
                continue;
            }
            if (in_array((string) $key, $sensitiveFields, true)) {
                $context[$key] = '[REDACTED]';
            }
        }
        return $context;
    }
}
