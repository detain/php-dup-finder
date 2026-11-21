<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

/**
 * Filesystem-safe projection of cluster ids (output-063).
 *
 * Seven reporters/extractors used to re-implement the same one-line
 * character-class projection inline; this class is now the single
 * definition of what "filename safe" means for an artifact name.
 *
 * The rule is byte-wise: every byte outside [a-zA-Z0-9_-] becomes a
 * single '_'. It deliberately carries no unicode (/u) modifier — the
 * projection is about path-segment safety, not text canonicalisation,
 * and byte semantics keep the output stable for ids that somehow
 * contain non-ASCII bytes.
 *
 * Round-trip law: ids minted by {@see FindingId} ('F' + 16 hex digits)
 * contain only safe bytes, so every legacy and current content id
 * passes through byte-identically — artifact filenames (`.diff`,
 * `.patch`, `Refactored/*.php`, `Cluster*Test.php`) do not move.
 */
final class Slug
{
    /** Bytes a path segment must not carry: everything outside [a-zA-Z0-9_-]. */
    private const UNSAFE_BYTE = '/[^a-zA-Z0-9_-]/';

    /**
     * Project a cluster id onto a filename-safe token.
     *
     * Pure and total: the pattern is a fixed byte class, so the only
     * preg_replace() failure mode (PCRE error) is structurally excluded;
     * the null coalesce exists solely to keep the return type honest
     * without a silent fallback path.
     */
    public static function safe(string $clusterId): string
    {
        $safe = preg_replace(self::UNSAFE_BYTE, '_', $clusterId);
        if ($safe === null) {
            throw new \RuntimeException('slug projection failed for cluster id: ' . $clusterId);
        }
        return $safe;
    }
}
