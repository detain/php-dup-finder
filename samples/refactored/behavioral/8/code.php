<?php

declare(strict_types=1);

namespace App\Text\Email;

final class EmailExtractor
{
    private const PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    public function firstEmail(string $text): ?string
    {
        if (preg_match_all(self::PATTERN, $text, $matches) === false) {
            return null;
        }

        foreach ($matches[0] as $candidate) {
            $normalized = $this->normalize($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function allEmails(string $text): array
    {
        if (preg_match_all(self::PATTERN, $text, $matches) === false) {
            return [];
        }

        $result = [];
        foreach ($matches[0] as $candidate) {
            $normalized = $this->normalize($candidate);
            if ($normalized !== null) {
                $result[] = $normalized;
            }
        }

        return array_values(array_unique($result));
    }

    private function normalize(string $candidate): ?string
    {
        $trimmed = trim($candidate, ".,;:!?'\"");

        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return strtolower($trimmed);
    }
}
