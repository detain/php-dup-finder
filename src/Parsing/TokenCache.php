<?php
declare(strict_types=1);

namespace Phpdup\Parsing;

use PhpParser\Lexer;
use PhpParser\ParserFactory;

/**
 * Disk-backed token-stream cache keyed by file content hash.
 *
 * The php-parser tokenisation step is allocator-heavy — for large
 * files it's often the dominant cost when the AST cache misses
 * (e.g. on first analysis of a fresh corpus, or after a parser
 * version bump). Caching the token stream lets us skip lex+parse
 * boundaries on revisits even when the AST itself can't be reused.
 *
 * The cache uses serialize() on the array of {@see PhpParser\Token}
 * structs returned by the lexer. Token objects are plain PHP value
 * objects (since php-parser 5.0) so they round-trip fine.
 *
 * Note: this is intentionally a separate cache from {@see AstCache}.
 * AstCache stores parsed Stmt[] (heavier, fully resolved) while
 * TokenCache stores the raw token stream (lighter, useful when the
 * caller wants to re-parse with a different node visitor configuration).
 */
final class TokenCache
{
    private const VERSION = 'tok-v1';

    public function __construct(private readonly string $cacheDir)
    {
        if ($this->cacheDir !== '' && !is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o775, true);
        }
    }

    public function isEnabled(): bool
    {
        return $this->cacheDir !== '' && is_dir($this->cacheDir);
    }

    /**
     * @return list<\PhpParser\Token>|null
     */
    public function get(string $path): ?array
    {
        if (!$this->isEnabled()) return null;
        $key = $this->keyFor($path);
        if ($key === null) return null;
        $file = $this->cacheDir . '/' . $key . '.tok';
        if (!is_file($file)) return null;
        $blob = @file_get_contents($file);
        if ($blob === false) return null;
        $data = @unserialize($blob, ['allowed_classes' => true]);
        return is_array($data) ? $data : null;
    }

    /** @param list<\PhpParser\Token> $tokens */
    public function put(string $path, array $tokens): void
    {
        if (!$this->isEnabled()) return;
        $key = $this->keyFor($path);
        if ($key === null) return;
        @file_put_contents($this->cacheDir . '/' . $key . '.tok', serialize($tokens), LOCK_EX);
    }

    private function keyFor(string $path): ?string
    {
        if (!is_file($path)) return null;
        $sha = @sha1_file($path);
        if ($sha === false) return null;
        return self::VERSION . '_' . $sha;
    }
}
