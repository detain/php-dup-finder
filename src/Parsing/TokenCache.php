<?php
declare(strict_types=1);

namespace Phpdup\Parsing;

use Composer\InstalledVersions;
use PhpParser\Token;
use Phpdup\Persistence\SerializedClassAllowList;

/**
 * Disk-backed token-stream cache keyed by file content hash.
 *
 * Status (catalog close-out, engine-S31c): PRE-WIRED, NOT INSTANTIATED
 * in production — `AstParser` still lexes inline, and the `src/`
 * references to this class are the AstCache/CacheBlob cross-links and
 * the SerializedClassAllowList trust anchor, not call sites. The
 * finding-era "dead decoder" label predates the engine-S23b
 * framing/seal and engine-S24 key honesty this class now carries;
 * the honest disposition is a hardened, tested API kept for its
 * wiring step.
 *
 * The php-parser tokenisation step is allocator-heavy — for large
 * files it's often the dominant cost when the AST cache misses
 * (e.g. on first analysis of a fresh corpus, or after a parser
 * version bump). Caching the token stream lets us skip lex+parse
 * boundaries on revisits even when the AST itself can't be reused.
 *
 * The cache uses serialize() on the array of {@see Token} structs
 * returned by the lexer. Token objects are plain PHP value objects
 * (since php-parser 5.0) so they round-trip fine.
 *
 * Blob integrity is the {@see CacheBlob} contract shared with
 * {@see AstCache} (engine-S23b): writes are length-framed so a
 * truncated or appended-to file fails closed as a miss, and decodes
 * run the recursive screen — a disallowed class smuggled into a
 * Token's dynamic slot, or into a typed slot where it aborts
 * unserialize with a TypeError, degrades to a miss instead of
 * poisoning the token stream or fataling the run (engine-018).
 *
 * Every key binds the parser package version and the host PHP major
 * (engine-016) through the same derivation AstCache gained in
 * engine-S24 — duplicated per file because the step's locks admit no
 * shared home for it, with TokenCacheTest::testKeyMarkerParityWithAstCache
 * pinning the two markers byte-equal so drift fails loud. The
 * path→sha1 memo (engine-050) and the fail-soft InstalledVersions
 * posture are mirrored for the same reason.
 *
 * Fresh cache dirs are 0700 and blobs are written 0600 (engine-020):
 * cached tokens embed the scanned source verbatim, so group-readable
 * cache files leak secrets and enable poisoning on shared runners.
 *
 * Note: this is intentionally a separate cache from {@see AstCache}.
 * AstCache stores parsed Stmt[] (heavier, fully resolved) while
 * TokenCache stores the raw token stream (lighter, useful when the
 * caller wants to re-parse with a different node visitor configuration).
 */
final class TokenCache
{
    /**
     * Blob storage-format id (engine-S23b's framing + value-object
     * screening generation), not a parser-version claim: since
     * engine-S24 keys embed the live package version + PHP major, so
     * parser releases invalidate by themselves and this literal moves
     * when the on-disk format changes — or when what a blob may MEAN to
     * its consumers changes, which is why item 7.2b bumped tok-v2 to
     * tok-v3 in the same edit that made the tokenizer's raw-text
     * (InlineHTML) runs feed the html_script window folds: cached token
     * streams taken under 7.2a keys were never consumed as template
     * window material, and the mission demands old caches invalidate on
     * the bump rather than half-feed a new species. Unframed v1 files
     * can never satisfy a v2 key, and tok-v2 blobs never satisfy a
     * tok-v3 key — fail closed even on a collision.
     * Keep this one line trivially mergeable — engine-S25 gates the
     * AstCache read path, not this file again.
     */
    private const VERSION = 'tok-v3';

    /** Composer package whose installed version keys cache invalidation. */
    private const PARSER_PACKAGE = 'nikic/php-parser';

    /** Entries kept in the process-wide path→sha1 memo before a reset. */
    private const MEMO_CAP = 50_000;

    /**
     * Process-wide path→content-sha memo (engine-050): a cold get+put
     * reads each file once, not twice, and warm calls never re-read it.
     *
     * @var array<string, array{0:int, 1:int, 2:int, 3:string}> path => [mtime, size, inode, sha1]
     */
    private static array $shaMemo = [];

    /** Real sha1_file() passes made this process — the engine-050 memo witness. */
    private static int $hashCount = 0;

    /**
     * @param string      $cacheDir               disk cache directory ('' disables the cache)
     * @param string|null $parserVersionOverride  pins the parser marker inside cache keys
     *                                            (the two-marker identity unit, engine-S24);
     *                                            production leaves it null and gets the
     *                                            InstalledVersions-derived marker
     */
    public function __construct(
        private readonly string $cacheDir,
        private readonly ?string $parserVersionOverride = null,
    ) {
        if ($this->cacheDir !== '' && !is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o700, true);
        }
    }

    public function isEnabled(): bool
    {
        return $this->cacheDir !== '' && is_dir($this->cacheDir);
    }

    /**
     * @return list<Token>|null
     */
    public function get(string $path): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $key = $this->keyFor($path);
        if ($key === null) {
            return null;
        }
        $payload = CacheBlob::read($this->cacheDir . '/' . $key . '.tok');
        if ($payload === null) {
            return null;
        }
        return CacheBlob::decode($payload, SerializedClassAllowList::parserClasses(), Token::class);
    }

    /** @param list<Token> $tokens */
    public function put(string $path, array $tokens): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $key = $this->keyFor($path);
        if ($key === null) {
            return;
        }
        try {
            $payload = serialize($tokens);
        } catch (\Throwable) {
            return; // "not cached" is always a safe outcome (engine-004 lesson)
        }
        CacheBlob::write($this->cacheDir . '/' . $key . '.tok', $payload);
    }

    /** @internal test witness for the engine-050 memo: hash passes actually run */
    public static function hashCount(): int
    {
        return self::$hashCount;
    }

    /**
     * Cache key: `<format-id>_<parser-version+php-major marker>_<content sha1>`.
     * Null means "no cache for this path" — the file is missing or unreadable.
     */
    private function keyFor(string $path): ?string
    {
        $sha = $this->contentSha($path);
        if ($sha === null) {
            return null;
        }
        return self::VERSION . '_' . $this->envMarker() . '_' . $sha;
    }

    /**
     * Environment fingerprint every key binds (engine-016): the installed
     * php-parser pretty version and the host PHP major, filename-sanitized.
     */
    private function envMarker(): string
    {
        $parser = $this->parserVersionOverride ?? self::installedParserVersion();
        return 'pp' . self::sanitize($parser) . '_php' . intdiv(PHP_VERSION_ID, 10000);
    }

    /**
     * The composer-derived truth behind the marker. Fail-soft by the cache
     * family contract ("a cache must never abort a run"): an unresolvable
     * package degrades to a stable 'unknown' label rather than throwing.
     */
    private static function installedParserVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion(self::PARSER_PACKAGE) ?? 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /** Reduce an arbitrary version label to filesystem-safe characters. */
    private static function sanitize(string $label): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '-', $label) ?? 'unknown';
    }

    /**
     * Memoized content hash of $path (engine-050).
     *
     * The memo trusts a fresh (mtime, size, inode) stat triple: whenever
     * the stat still matches what was hashed, the cached sha1 is returned
     * with no file read at all; any change re-runs sha1_file(), so the
     * content-addressed key keeps its meaning and edited files miss as
     * before (same contract, same accepted race, as AstCache::contentSha).
     */
    private function contentSha(string $path): ?string
    {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return null;
        }
        $stat = stat($path);
        if ($stat === false) {
            return null; // raced away between the guard and the stat
        }
        $mtime = (int)($stat['mtime'] ?? 0);
        $size = (int)($stat['size'] ?? -1);
        $inode = (int)($stat['ino'] ?? -1);

        $memo = self::$shaMemo[$path] ?? null;
        if (
            $memo !== null
            && $memo[0] === $mtime
            && $memo[1] === $size
            && $memo[2] === $inode
        ) {
            return $memo[3];
        }

        self::$hashCount++;
        $sha = @sha1_file($path);
        if ($sha === false) {
            return null;
        }
        if (count(self::$shaMemo) >= self::MEMO_CAP) {
            self::$shaMemo = []; // bounded memory beats an unbounded memo
        }
        self::$shaMemo[$path] = [$mtime, $size, $inode, $sha];
        return $sha;
    }
}
