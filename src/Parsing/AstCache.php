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
 * Deserialization is restricted to the PhpParser node namespace via
 * {@see SerializedClassAllowList::parserClasses()}; any unexpected
 * class hiding in a tampered cache file decodes as an
 * `__PHP_Incomplete_Class` and is treated as a miss.
 */
final class AstCache
{
    private const VERSION = 'parser-v5';

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
        $file = $this->cacheDir . '/' . $key . '.cache';
        if (!is_file($file)) {
            return null;
        }
        $blob = @file_get_contents($file);
        if ($blob === false) {
            return null;
        }
        $data = @unserialize($blob, ['allowed_classes' => SerializedClassAllowList::parserClasses()]);
        if (!is_array($data)) {
            return null;
        }
        // If any element decoded as __PHP_Incomplete_Class the blob was
        // tampered with or written by an incompatible parser version —
        // refuse to feed it back to downstream stages.
        foreach ($data as $node) {
            if ($node instanceof \__PHP_Incomplete_Class) {
                return null;
            }
        }
        return $data;
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
        $file = $this->cacheDir . '/' . $key . '.cache';
        @file_put_contents($file, serialize($stmts), LOCK_EX);
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
