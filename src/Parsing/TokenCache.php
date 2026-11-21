<?php
declare(strict_types=1);

namespace Phpdup\Parsing;

use PhpParser\Token;
use Phpdup\Persistence\SerializedClassAllowList;

/**
 * Disk-backed token-stream cache keyed by file content hash.
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
     * v2: blobs gained CacheBlob length framing + value-object decode
     * screening (engine-S23b, AstCache's parser-v6 precedent). Unframed
     * v1 files can never satisfy a v2 key, and fail closed even if a
     * key collided, so stale entries simply miss. Keep this one line
     * trivially mergeable — engine-S24/S25 rework keyFor().
     */
    private const VERSION = 'tok-v2';

    public function __construct(private readonly string $cacheDir)
    {
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

    private function keyFor(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $sha = @sha1_file($path);
        if ($sha === false) {
            return null;
        }
        return self::VERSION . '_' . $sha;
    }
}
