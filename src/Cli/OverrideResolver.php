<?php
declare(strict_types=1);

namespace Phpdup\Cli;

/**
 * Owns the precedence rule overrides → data → base for a flat key list.
 *
 * Replaces hand-rolled `?? $overrides ?? $data ?? $default` chains for
 * simple scalar configuration keys.
 *
 * @see ConfigLoader::load()  Primary consumer; passes the ~19 flat keys that
 *                             share the same three-tier precedence.
 */
final class OverrideResolver
{
    /**
     * @param list<string> $keys The canonical snake_case config keys that
     *                           this resolver will resolve through the
     *                           overrides → data → base chain.  Keys not in
     *                           this list are silently ignored (they are
     *                           handled via separate fallback logic in
     *                           ConfigLoader, e.g. allowed_kinds, optional_blocks,
     *                           db_aware, etc.).
     */
    public function __construct(
        private readonly array $keys,
    ) {
    }

    /**
     * Resolve the precedence chain overrides → data → base for all keys.
     *
     * @param array<string,mixed> $overrides CLI or runtime overrides (highest priority)
     * @param array<string,mixed> $data      Loaded config file data (medium priority)
     * @param array<string,mixed> $base      Defaults (lowest priority)
     * @return array<string,mixed> Flat key => resolved value map
     */
    public function resolve(array $overrides, array $data, array $base): array
    {
        $out = [];
        foreach ($this->keys as $key) {
            if (array_key_exists($key, $overrides)) {
                $out[$key] = $overrides[$key];
            } elseif (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            } elseif (array_key_exists($key, $base)) {
                $out[$key] = $base[$key];
            }
        }
        return $out;
    }
}
