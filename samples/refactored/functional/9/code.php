<?php
declare(strict_types=1);

namespace Acme\Chat\Language;

interface LanguageDetectorDriver
{
    public function bestGuess(string $text): ?string;
}

final class LanguageDetector
{
    public function __construct(private readonly LanguageDetectorDriver $driver) {}

    public function detect(string $text): ?string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text, 'UTF-8') < 2) {
            return null;
        }
        $code = $this->driver->bestGuess($text);
        if ($code === null) {
            return null;
        }
        $code = strtolower($code);
        return preg_match('/^[a-z]{2}$/', $code) === 1 ? $code : null;
    }
}
