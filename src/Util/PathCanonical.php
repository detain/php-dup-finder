<?php
declare(strict_types=1);

namespace Phpdup\Util;

/**
 * Machine-portable canonical form of a scanned file path.
 *
 * FileScanner yields paths formed by the literal scan root plus the
 * traversal suffix, so stripping the longest matching root turns a
 * checkout-location-dependent path into a scan-scoped relative one.
 * Two machines cloning the same repository and invoking the same scan
 * therefore agree on every canonical form — the property finding ids
 * and CI baselines need to survive being produced on a runner and
 * consumed on a laptop (GoldenTest's "portable across machines" law).
 *
 * A path matching no root (a file given as its own root, a synthetic
 * test block) keeps its bytes verbatim: identity is still deterministic
 * for the run, just not portable across machines — the honest fallback.
 */
final class PathCanonical
{
    /**
     * @param list<string> $roots the configured scan roots, raw form
     */
    public static function underRoots(string $path, array $roots): string
    {
        $bestLen = 0;
        foreach ($roots as $root) {
            $prefix = rtrim($root, '/') . '/';
            $len = strlen($prefix);
            if ($len > $bestLen && str_starts_with($path, $prefix)) {
                $bestLen = $len;
            }
        }
        if ($bestLen > 0) {
            $rel = substr($path, $bestLen);
            return $rel === '' ? basename($path) : $rel;
        }

        // A root naming a single file (FileScanner yields it verbatim):
        // the path IS the root — reduce to its basename so the machine
        // prefix carries no more weight than in the directory case.
        foreach ($roots as $root) {
            if ($path === $root || $path === rtrim($root, '/')) {
                return basename($path);
            }
        }

        return $path;
    }
}
