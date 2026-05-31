<?php
declare(strict_types=1);

namespace Acme\Vehicles\Normalize;

final class LicensePlateNormalizer
{
    /** @var array<string,string> */
    private array $defaults = [
        'state'  => '',
        'prefix' => '',
        'serial' => '',
        'suffix' => '',
    ];

    /**
     * @return array<string,string>
     */
    public function parse(string $raw): array
    {
        $regex = '/^([A-Z]{2})-([A-Z]{1,3})(\d{1,4})([A-Z]{0,2})$/';

        // identical token-shape: preg_match + array_merge of captured slots
        $matches = [];
        if (preg_match($regex, $raw, $matches) === 1) {
            return array_merge($this->defaults, [
                'state'  => $matches[1],
                'prefix' => $matches[2],
                'serial' => $matches[3],
                'suffix' => $matches[4] ?? '',
            ]);
        }
        return $this->defaults;
    }
}
