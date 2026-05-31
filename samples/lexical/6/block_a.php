<?php
declare(strict_types=1);

namespace Acme\Contacts\Normalize;

final class PhoneNumberNormalizer
{
    /** @var array<string,string> */
    private array $defaults = [
        'country' => 'US',
        'area'    => '',
        'number'  => '',
        'ext'     => '',
    ];

    /**
     * @return array<string,string>
     */
    public function parse(string $raw): array
    {
        $regex = '/^\+?1?\D*(\d{3})\D*(\d{3})\D*(\d{4})(?:\s*x(\d+))?$/';

        // canonical: preg_match -> if matched -> array_merge with named pieces
        $matches = [];
        if (preg_match($regex, $raw, $matches) === 1) {
            return array_merge($this->defaults, [
                'area'   => $matches[1],
                'number' => $matches[2] . $matches[3],
                'ext'    => $matches[4] ?? '',
            ]);
        }
        return $this->defaults;
    }

    /**
     * @param iterable<string> $raws
     * @return array<string, array<string,string>>
     */
    public function parseAll(iterable $raws): array
    {
        $out = [];
        foreach ($raws as $raw) {
            $out[$raw] = $this->parse($raw);
        }
        return $out;
    }
}
