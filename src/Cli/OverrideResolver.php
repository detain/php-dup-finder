<?php
declare(strict_types=1);

namespace Phpdup\Cli;

/**
 * Owns the precedence rule overrides → data → base for a flat key list.
 *
 * Replaces hand-rolled `?? $overrides ?? $data ?? $default` chains for
 * simple scalar configuration keys.
 *
 * @see ConfigLoader::load()  Primary consumer; passes the flat keys it shares
 *                             the same three-tier precedence over — the table
 *                             of record is `ConfigLoader::$flatKeys` +
 *                             `NUMERIC_KEYS` (never hard-count it here).
 */
final class OverrideResolver
{
    /**
     * @param list<string> $keys The canonical snake_case config keys that
     *                           this resolver will resolve through the
     *                           overrides → data → base chain.  Keys not in
     *                           this list are NOT ignored: ConfigLoader
     *                           consumes them through dedicated legs —
     *                           resolved by hand next to load() (allowed_kinds,
     *                           fingerprint_id_mode, the exclude/exclude_reset
     *                           merge), hoisted from nested objects (the
     *                           optional_blocks_* flat keys ride this roster
     *                           via that hoist), or admitted exclusively to
     *                           the per-directory layer (db_aware,
     *                           trinity_collapse, db_symbols_*, low_memory,
     *                           once-S67). Everything a subtree file may
     *                           carry flows through shapeOverrides(); the
     *                           rest of the KNOWN_KEYS set is refused at
     *                           discovery. Truth-touch per the admission-
     *                           totality item (php-dup-finder-once-077).
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
