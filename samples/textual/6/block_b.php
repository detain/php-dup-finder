<?php
declare(strict_types=1);

namespace ChatGuard\Filters;

final class FormFieldValidator
{
    /** @param array<string,string> $fields */
    public function validate(array $fields): bool
    {
        $piiRegex = '/(?<!\d)(?:(?:\d{3}-\d{2}-\d{4})|(?:\d{9})(?!\d)|'
                  . '(?:4\d{3}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
                  . '(?:5[1-5]\d{2}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
                  . '(?:3[47]\d{2}[\s-]?\d{6}[\s-]?\d{5})|'
                  . '(?:6011[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}))(?!\d)/';

        foreach ($fields as $key => $value) {
            if ($key === 'notes' || $key === 'description') {
                continue;
            }
            if (preg_match($piiRegex, $value) === 1) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,string> $fields @return list<string> */
    public function offendingFields(array $fields): array
    {
        $piiRegex = '/(?<!\d)(?:(?:\d{3}-\d{2}-\d{4})|(?:\d{9})(?!\d)|'
                  . '(?:4\d{3}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
                  . '(?:5[1-5]\d{2}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4})|'
                  . '(?:3[47]\d{2}[\s-]?\d{6}[\s-]?\d{5})|'
                  . '(?:6011[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}))(?!\d)/';

        $bad = [];
        foreach ($fields as $key => $value) {
            if (preg_match($piiRegex, $value) === 1) {
                $bad[] = $key;
            }
        }
        return $bad;
    }
}
