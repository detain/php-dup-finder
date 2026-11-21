<?php
declare(strict_types=1);

namespace Phpdup\Parsing;

use PhpParser\Node;
use Phpdup\Persistence\SerializedClassAllowList;

/**
 * Disk-backed AST cache keyed by file content hash + parser version.
 *
 * Uses serialize/unserialize on PhpParser nodes — they're plain PHP
 * objects with no closures so this round-trips fine. The cache key
 * includes the parser package version so a parser bump invalidates
 * everything automatically.
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
     * v6: blobs gained CacheBlob length framing (engine-017); framing
     * mismatches fail closed, and stale v5 files can never satisfy the
     * new keys, so old entries simply miss. Keep this one line
     * trivially mergeable — engine-S24/S25 rework keyFor().
     */
    private const VERSION = 'parser-v6';

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
