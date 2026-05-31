<?php
declare(strict_types=1);

namespace ChatGuard\Filters;

final class MessageBodyValidator
{
    public function validate(string $messageBody): bool
    {
        $piiRegex = '/(?<!\d)(?:(?:\d{3}-\d{2}-\d{4})|(?:\d{9})(?!\d)|'
                  . '(?:4\d{3}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
                  . '(?:5[1-5]\d{2}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
                  . '(?:3[47]\d{2}[\s-]?\d{6}[\s-]?\d{5})|'
                  . '(?:6011[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}))(?!\d)/';

        if (trim($messageBody) === '') {
            return false;
        }
        if (mb_strlen($messageBody) > 4000) {
            return false;
        }
        if (preg_match($piiRegex, $messageBody) === 1) {
            return false;
        }
        return true;
    }

    public function explain(string $messageBody): string
    {
        $piiRegex = '/(?<!\d)(?:(?:\d{3}-\d{2}-\d{4})|(?:\d{9})(?!\d)|'
                  . '(?:4\d{3}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
                  . '(?:5[1-5]\d{2}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
                  . '(?:3[47]\d{2}[\s-]?\d{6}[\s-]?\d{5})|'
                  . '(?:6011[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}))(?!\d)/';

        return preg_match($piiRegex, $messageBody) === 1 ? 'PII detected' : 'ok';
    }
}
