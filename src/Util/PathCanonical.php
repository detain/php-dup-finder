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
 * Windows portability (once-044): paths join with the platform native
 * separator, so a backslash-joined tree (C:\proj\src\A.php) previously
 * never matched a slash-built root prefix, never stripped, and silently
 * escaped both the anchored default excludes and the id/baseline
 * canonicalization. The cure lives HERE, at the single boundary this
 * class already owns: root matching folds separators (and ASCII case,
 * the macOS/Windows norm) before comparison, and every emitted form
 * goes through {@see separators()}. Both boundary call sites —
 * FileScanner::relpath() and the id/baseline recipes in
 * Reporting\FindingId / Reporting\BaselineStore — route through
 * underRoots(), so no second implementation can drift.
 *
 * Backslash-as-separator law: on these boundary surfaces a "\" folds
 * to "/". A Linux filename containing a literal backslash therefore
 * matches excludes (and hashes into ids) in its folded shape — such a
 * name never matched a root-relative anchored exclude before either,
 * and the golden fixtures carry no backslash filenames. Exclusion
 * MATCHING itself stays case-sensitive: case folding governs only the
 * root-strip comparison, so tests/** and Tests/ stay distinct here —
 * pinned by FileScannerTest::testS40ExclusionMatchingStaysCaseSensitive
 * (once-S40 fix-round-1: the law was prose-only before it).
 *
 * A path matching no root keeps its form (a synthetic test block, an
 * unrelated absolute path) with separators folded: identity is still
 * deterministic for the run and the drive prefix can no longer leak
 * machine bytes into portable ids — the honest fallback.
 */
final class PathCanonical
{
    /**
     * Emission normalizer: native separators to the canonical
     * forward-slash form. Length-preserving, which is what lets
     * {@see underRoots()} compare in the folded space yet still strip
     * the raw path at the same byte offset.
     */
    public static function separators(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Comparison key: {@see separators()} plus ASCII-case folding.
     * Length-preserving and locale-independent (strtr, never
     * strtolower — mb/locale behaviour must not steer path identity at
     * the PHP 8.1 floor). Case folding serves root matching only; it
     * never reaches an emitted form.
     */
    public static function fold(string $path): string
    {
        return strtr(self::separators($path), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /**
     * @param list<string> $roots the configured scan roots, raw form
     */
    public static function underRoots(string $path, array $roots): string
    {
        $folded = self::fold($path);
        $bestLen = 0;
        foreach ($roots as $root) {
            $prefix = self::fold(rtrim($root, '/\\')) . '/';
            $len = strlen($prefix);
            if ($len > $bestLen && str_starts_with($folded, $prefix)) {
                $bestLen = $len;
            }
        }
        if ($bestLen > 0) {
            // fold() preserves length, so the folded-prefix length is
            // exactly the raw-path byte offset; the raw bytes (case and
            // all) carry the stripped remainder.
            $rel = substr($path, $bestLen);
            return $rel === '' ? basename(self::separators($path)) : self::separators($rel);
        }

        // A root naming a single file (FileScanner yields it verbatim):
        // the path IS the root — reduce to its basename so the machine
        // prefix carries no more weight than in the directory case.
        foreach ($roots as $root) {
            if ($folded === self::fold($root) || $folded === self::fold(rtrim($root, '/\\'))) {
                return basename(self::separators($path));
            }
        }

        return self::separators($path);
    }

    /**
     * Disk-identity key (once-S87, php-dup-finder-once-109 DET-04): the
     * resolved real path when the disk answers, otherwise the
     * separator-folded literal. Used by ScanningStage to collapse roots
     * that name the same disk location (`analyze src src`, `src/` plus
     * `src`, or two spellings reached through a symlink) and to keep one
     * representative per file when distinct-but-overlapping roots walk
     * the same subtree twice.
     *
     * This is deliberately NOT underRoots() territory: no root-strip, no
     * case folding (the once-S40 case-sensitivity law governs matching,
     * and realpath() already resolves exactly what the OS resolves), and
     * no relpath emission — the key is a set-membership token, never a
     * reported path. Paths the disk cannot resolve (not yet created,
     * unreadable parents) fall back to the folded literal, which keeps
     * the key deterministic for the run: the same unreachable spelling
     * always yields the same key, so dedupe stays order-insensitive.
     */
    public static function diskKey(string $path): string
    {
        $real = realpath($path);
        return $real !== false ? $real : self::separators($path);
    }

    /**
     * Unified-diff header operand (once-S101, php-dup-finder-once-126
     * XP-11): the path that follows the `a/` / `b/` prefix in a
     * `--- a/<path>` / `+++ b/<path>` header line.
     *
     * Git's own convention, proven against `git diff --no-index
     * /tmp/x/f.php /tmp/y/f.php`: absolute operands print as
     * `--- a/tmp/x/f.php` — the leading slash IS consumed by the prefix
     * join, it does not double. Plain interpolation (`"--- a/{$file}"`)
     * minted `--- a//tmp/...` for every absolute scan path, a spelling no
     * unified-diff reader produces. This join folds separators first —
     * the class-wide Windows law — then sheds leading slashes, so the
     * header always reads like git's.
     */
    public static function diffOperand(string $path): string
    {
        return ltrim(self::separators($path), '/');
    }
}
