<?php

declare(strict_types=1);

namespace App\Services\Content;

final class SlugConfig
{
    public readonly int $maxLength;
    public readonly int $minLength;
    public readonly string $delimiter;

    public function __construct(
        int $maxLength = 100,
        int $minLength = 2,
        string $delimiter = '-'
    ) {
        $this->maxLength = $maxLength;
        $this->minLength = $minLength;
        $this->delimiter = $delimiter;
    }
}

final class SlugService
{
    private SlugConfig $config;

    private const TRANSLITERATIONS = [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ñ' => 'n', 'ñ' => 'n', 'Ç' => 'C', 'ç' => 'c',
    ];

    public function __construct(SlugConfig $config)
    {
        $this->config = $config;
    }

    public function generate(string $title): string
    {
        $slug = strtr(mb_strtolower($title, 'UTF-8'), self::TRANSLITERATIONS);
        $slug = preg_replace('/[^\p{L}\p{N}\-_]/u', $this->config->delimiter, $slug);
        $slug = preg_replace('/' . preg_quote($this->config->delimiter, '/') . '{2,}/', $this->config->delimiter, $slug);
        $slug = trim($slug, $this->config->delimiter);

        if (strlen($slug) < $this->config->minLength) {
            throw new \InvalidArgumentException('Slug too short');
        }

        return substr($slug, 0, $this->config->maxLength);
    }

    public function generateUnique(string $title, array $existing = []): string
    {
        $slug = $this->generate($title);
        $candidate = $slug;
        $counter = 1;

        while (in_array($candidate, $existing, true)) {
            $candidate = $slug . $this->config->delimiter . $counter++;
        }

        return $candidate;
    }
}
