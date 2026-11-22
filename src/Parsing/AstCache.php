<?php
declare(strict_types=1);

namespace Phpdup\Parsing;

use Composer\InstalledVersions;
use PhpParser\Node;
use Phpdup\Persistence\SerializedClassAllowList;

/**
 * Disk-backed AST cache keyed by file content hash + parser version.
 *
 * Uses serialize/unserialize on PhpParser nodes — they're plain PHP
 * objects with no closures so this round-trips fine.
 *
 * Every cache key binds the environment that produced the blob
 * (engine-016): the storage-format constant below, the installed
 * nikic/php-parser package version read from Composer\InstalledVersions,
 * the host PHP major (the parser is createForHostVersion()-built, so
 * trees parsed under two PHP majors are not interchangeable), and the
 * file's content hash. A parser or PHP bump therefore invalidates every
 * old key automatically — the docblock promise this class used to make
 * with a hand-bumped literal is now the actual mechanism. The key
 * derivation is duplicated in {@see TokenCache} by necessity (no shared
 * file inside engine-S24's locks); TokenCacheTest pins the two markers
 * byte-equal so they cannot drift silently.
 *
 * Blob storage and integrity live in {@see CacheBlob}, shared with
 * {@see TokenCache}: writes are length-framed so truncated or
 * appended-to files fail closed as a miss, and decodes are screened
 * recursively — any unexpected class, top-level or hidden in a nested
 * slot, yields a miss, never a poisoned AST and never a fatal.
 *
 * The write side refuses deeply nested user code (engine-004): past a
 * few thousand node levels `serialize()` can exhaust the engine stack
 * and abort the whole run, so oversized ASTs are simply not cached —
 * with a belt-and-braces catch in case serialization fails anyway.
 *
 * Fresh cache dirs are 0700 and blobs are 0600 (engine-020): cached
 * ASTs embed every string literal of the scanned sources, so a
 * group-readable cache leaks secrets and enables poisoning on shared
 * runners. Shared caches across trust boundaries are unsupported.
 */
final class AstCache
{
    /**
     * Blob storage-format id (the length-framing + recursive-screening
     * generation), NOT a parser-version claim: since engine-S24 every
     * key embeds the live package version and PHP major, so parser
     * releases invalidate by themselves and this literal moves only
     * when the on-disk format changes — stale-format files can never
     * satisfy a new key, and fail closed even on a name collision.
     * Keep this one line trivially mergeable — engine-S25 reads the
     * final key derivation for its trust gate next.
     */
    private const VERSION = 'parser-v6';

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
     * @return list<Node\Stmt>|null
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
        $payload = CacheBlob::read($this->cacheDir . '/' . $key . '.cache');
        if ($payload === null) {
            return null;
        }
        return CacheBlob::decode($payload, SerializedClassAllowList::parserClasses(), Node\Stmt::class);
    }

    /**
     * @param list<Node\Stmt> $stmts
     */
    public function put(string $path, array $stmts): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $key = $this->keyFor($path);
        if ($key === null) {
            return;
        }
        if (CacheBlob::exceedsDepth($stmts)) {
            return; // hostile shape: leave it uncached, the pipeline survives without it
        }
        try {
            $payload = serialize($stmts);
        } catch (\Throwable) {
            return; // "not cached" is always a safe outcome
        }
        CacheBlob::write($this->cacheDir . '/' . $key . '.cache', $payload);
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
     * package degrades to a stable 'unknown' label, which is exactly the
     * hand-bumped posture the VERSION constant used to pretend was dynamic.
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
     * before. The residual same-second, same-size, same-inode rewrite is
     * the race every mtime-guarded build cache accepts — closing it would
     * mean re-hashing the corpus, the exact cost this memo kills.
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
