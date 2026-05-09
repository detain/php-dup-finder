<?php
declare(strict_types=1);

namespace Phpdup\Scanning;

/**
 * Recursive PHP file scanner with glob include/exclude semantics.
 *
 * Globs use ** for "any depth" and * for "any segment". Patterns are
 * matched against the path relative to the scan root.
 */
final class FileScanner
{
    /** @var list<string> */
    private array $excludeRegexes;

    /**
     * @param list<string> $excludeGlobs
     */
    public function __construct(array $excludeGlobs)
    {
        $this->excludeRegexes = array_map([$this, 'globToRegex'], $excludeGlobs);
    }

    /**
     * @return iterable<string> absolute file paths
     */
    public function scan(string $root): iterable
    {
        $root = rtrim($root, '/');
        if (!is_dir($root)) {
            if (is_file($root) && $this->looksLikePhp($root)) {
                yield $root;
            }
            return;
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                function (\SplFileInfo $current) use ($root): bool {
                    $rel = $this->relpath($current->getPathname(), $root);
                    if ($rel === '') {
                        return true;
                    }
                    if ($current->isDir()) {
                        return !$this->isExcluded($rel . '/');
                    }
                    return !$this->isExcluded($rel);
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iter as $info) {
            /** @var \SplFileInfo $info */
            if (!$info->isFile()) {
                continue;
            }
            if (!$this->looksLikePhp($info->getPathname())) {
                continue;
            }
            yield $info->getPathname();
        }
    }

    private function looksLikePhp(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['php', 'php8', 'phtml', 'inc'], true);
    }

    private function relpath(string $abs, string $root): string
    {
        if (str_starts_with($abs, $root . '/')) {
            return substr($abs, strlen($root) + 1);
        }
        if ($abs === $root) {
            return '';
        }
        return $abs;
    }

    public function isExcluded(string $relPath): bool
    {
        foreach ($this->excludeRegexes as $re) {
            if (preg_match($re, $relPath) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Translate a shell-style glob (with ** for any-depth) to a PCRE regex
     * anchored to the start, with optional trailing slash for directories.
     */
    private function globToRegex(string $glob): string
    {
        $glob = ltrim($glob, '/');
        $re = '';
        $i = 0;
        $len = strlen($glob);
        while ($i < $len) {
            $c = $glob[$i];
            if ($c === '*') {
                if ($i + 1 < $len && $glob[$i + 1] === '*') {
                    $re .= '.*';
                    $i += 2;
                    if ($i < $len && $glob[$i] === '/') {
                        $i++;
                    }
                } else {
                    $re .= '[^/]*';
                    $i++;
                }
            } elseif ($c === '?') {
                $re .= '[^/]';
                $i++;
            } elseif ($c === '.') {
                $re .= '\.';
                $i++;
            } elseif ($c === '/') {
                $re .= '/';
                $i++;
            } else {
                $re .= preg_quote($c, '#');
                $i++;
            }
        }
        return '#^' . $re . '$#';
    }
}
