<?php
declare(strict_types=1);

namespace Acme\Address\Normalize;

final class PostalCodeNormalizer
{
    /** @var array<string,string> */
    private array $defaults = [
        'country' => 'US',
        'zip5'    => '',
        'zip4'    => '',
        'region'  => '',
    ];

    /**
     * @return array<string,string>
     */
    public function parse(string $raw): array
    {
        $regex = '/^(\d{5})(?:-(\d{4}))?(?:\s+([A-Z]{2}))?$/';

        // same lexeme stream: preg_match + array_merge with captured pieces
        $matches = [];
        if (preg_match($regex, $raw, $matches) === 1) {
            return array_merge($this->defaults, [
                'zip5'   => $matches[1],
                'zip4'   => $matches[2] ?? '',
                'region' => $matches[3] ?? '',
            ]);
        }
        return $this->defaults;
    }

    public function isValid(string $raw): bool
    {
        return $this->parse($raw) !== $this->defaults;
    }
}
