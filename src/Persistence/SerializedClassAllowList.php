<?php
declare(strict_types=1);

namespace Phpdup\Persistence;

/**
 * Builds explicit class allow-lists for {@see unserialize()} on the
 * disk caches.
 *
 * Why explicit lists? `unserialize($blob, ['allowed_classes' => true])`
 * accepts *any* class the autoloader can find — that is the textbook
 * PHP-object-injection footgun, and SAST tools (Codacy, Psalm taint
 * mode, etc.) flag the literal `true` on sight.
 *
 * The phpdup caches store either:
 *   - PhpParser AST nodes (AstCache); or
 *   - phpdup's own value-objects (ClusterCache, IndexStore: Cluster /
 *     Block / Hole / LineRange) which themselves carry PhpParser nodes.
 *
 * We restrict deserialization to those classes only. Any cache entry
 * containing an unexpected class name lands as `__PHP_Incomplete_Class`,
 * which our load paths treat as a cache miss.
 */
final class SerializedClassAllowList
{
    /** @var list<class-string>|null Memoized parser-class enumeration. */
    private static ?array $parserClasses = null;

    /**
     * The phpdup value-object classes that can appear inside a cached
     * cluster set or per-file block list.
     *
     * @return list<class-string>
     */
    public static function blockObjectClasses(): array
    {
        return [
            \Phpdup\Clustering\Cluster::class,
            \Phpdup\Extraction\Block::class,
            \Phpdup\Refactor\Hole::class,
            \Phpdup\Util\LineRange::class,
        ];
    }

    /**
     * Every PhpParser AST node class registered with composer's
     * autoloader. Memoized per-process — the autoloader's class map
     * doesn't change at runtime.
     *
     * @return list<class-string>
     */
    public static function parserClasses(): array
    {
        if (self::$parserClasses !== null) {
            return self::$parserClasses;
        }
        $out = [];
        foreach (spl_autoload_functions() ?: [] as $autoloader) {
            if (!is_array($autoloader) || count($autoloader) !== 2) {
                continue;
            }
            $loader = $autoloader[0];
            if (!is_object($loader) || !method_exists($loader, 'getClassMap')) {
                continue;
            }
            /** @var array<string,string> $classMap */
            $classMap = $loader->getClassMap();
            foreach (array_keys($classMap) as $fqn) {
                if (is_string($fqn) && str_starts_with($fqn, 'PhpParser\\')) {
                    /** @var class-string $fqn */
                    $out[] = $fqn;
                }
            }
        }
        // If the project was installed without composer's classmap
        // optimizer (`composer install` without `-o`) the map will be
        // empty for these namespaces. Fall back to walking the package
        // directory directly.
        if ($out === []) {
            $out = self::scanPackageDir(__DIR__ . '/../../vendor/nikic/php-parser/lib/PhpParser', 'PhpParser');
        }
        $out = array_values(array_unique($out));
        self::$parserClasses = $out;
        return $out;
    }

    /**
     * The combined allow-list used when reading a cached AST blob:
     * parser node classes plus phpdup's own value-objects (so a Block
     * with an embedded canonical AST round-trips intact).
     *
     * @return list<class-string>
     */
    public static function blockCacheClasses(): array
    {
        return array_values(array_unique(array_merge(self::blockObjectClasses(), self::parserClasses())));
    }

    /**
     * @return list<class-string>
     */
    private static function scanPackageDir(string $dir, string $namespace): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = substr((string)$file->getPathname(), strlen($dir));
            $rel = ltrim($rel, '/\\');
            $rel = (string)preg_replace('/\.php$/', '', $rel);
            $rel = str_replace(['/', '\\'], '\\', $rel);
            $fqn = rtrim($namespace, '\\') . '\\' . $rel;
            if (class_exists($fqn) || interface_exists($fqn) || trait_exists($fqn)) {
                /** @var class-string $fqn */
                $out[] = $fqn;
            }
        }
        return $out;
    }
}
