<?php
declare(strict_types=1);

namespace Acme\Common\Normalize;

/**
 * Apply a regex and project matched groups onto a defaults map.
 */
final class RegexProjection
{
    /**
     * @param array<string,string>     $defaults  starting key/value pairs
     * @param array<string,int|string> $projection  map of result-key => capture-index/name
     * @return array<string,string>
     */
    public static function project(
        string $regex,
        string $input,
        array $defaults,
        array $projection,
    ): array {
        $matches = [];
        if (preg_match($regex, $input, $matches) !== 1) {
            return $defaults;
        }
        $extracted = [];
        foreach ($projection as $key => $captureIndex) {
            $extracted[$key] = (string)($matches[$captureIndex] ?? '');
        }
        return array_merge($defaults, $extracted);
    }
}

// usage
// RegexProjection::project($phoneRegex, $raw, $phoneDefaults, [
//     'area'   => 1,
//     'number' => 2, // (concat handled in caller-side post-step)
//     'ext'    => 4,
// ]);
