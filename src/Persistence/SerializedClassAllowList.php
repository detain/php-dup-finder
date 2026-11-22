<?php
declare(strict_types=1);

namespace Phpdup\Persistence;

/**
 * Builds explicit class allow-lists for {@see unserialize()} on the
 * disk caches and the worker wire.
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
 *
 * **engine-S39 curation rule (php-dup-finder-engine-058):** the
 * PhpParser family is admitted by property, not by namespace. A
 * `PhpParser\` class is allowed only when it is a subclass of
 * {@see \PhpParser\Node}, or when it appears in
 * {@see self::BLOB_VALUE_CLASSES} — the enumerated non-Node value
 * objects honest blobs really carry. Before this rule the entire
 * `PhpParser\` prefix was auto-trusted (270 classes, 75 of them NOT
 * nodes: parser machinery, visitors, pretty-printers, and the
 * `PhpParser\Error` / `PhpParser\ConstExprEvaluationException`
 * Exception subclasses arriving with the inherited `__wakeup` hook).
 * Handing those to `unserialize()` on hostile bytes is the gadget
 * entry point the prefix admission existed to avoid. The companion
 * test
 * {@see \Phpdup\Tests\Unit\Persistence\SerializedClassAllowListTest::testNoAllowedClassCarriesUnserializeHooks}
 * fails the build if any admitted class ever grows a
 * `__destruct`/`__wakeup`/`__unserialize` hook.
 */
final class SerializedClassAllowList
{
    /**
     * The non-Node PhpParser classes that legitimately appear inside a
     * real cache blob or worker frame. This is the sanctioned
     * widening of the Node-subclass rule — each entry names the blob
     * family that needs it, and each stays a scalar-only leaf that
     * {@see \Phpdup\Parsing\CacheBlob}'s screens walk. Adding a class
     * here requires the same evidence, and it must survive the
     * unserialize-hook test above.
     *
     * - `Token` — the TokenCache element class (engine-018); its blob
     *   is exactly a list of these.
     * - `Comment` and its `Comment\Doc` subclass — the `comments`
     *   attribute leaf php-parser's CommentAnnotatingVisitor attaches
     *   at the end of every `parse()` of commented source
     *   (engine-S23a r2): without them every honest commented file
     *   would decode to an incomplete class and become a permanent
     *   cache miss (and every `--workers` run of commented code would
     *   trip WorkerPool's blocked-class fail-loud).
     *
     * Measured over the whole `src/` corpus, the token stream and the
     * wire shapes: no other non-Node class ever appears.
     *
     * @var list<class-string>
     */
    public const BLOB_VALUE_CLASSES = [
        \PhpParser\Token::class,
        \PhpParser\Comment::class,
        \PhpParser\Comment\Doc::class,
    ];

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
     * The AST-node family admitted to decode: every autoloadable
     * `PhpParser\` class that is a subclass of {@see \PhpParser\Node},
     * plus {@see self::BLOB_VALUE_CLASSES}.
     *
     * Candidate *names* are discovered from composer's class map
     * (falling back to a package-directory walk when the tree ships
     * without one); membership is decided by the Node property — the
     * prefix alone never admits a class (engine-S39).
     *
     * Memoized per-process. The predicate consults triggers the
     * one-time autoload of the library (~300 class files, most already
     * live in any process that reaches this path) and keeps the list
     * deterministic instead of load-order dependent.
     *
     * @return list<class-string>
     */
    public static function parserClasses(): array
    {
        if (self::$parserClasses !== null) {
            return self::$parserClasses;
        }
        $out = [];
        foreach (self::discoverPhpParserClassNames() as $fqn) {
            // is_subclass_of() consults the autoloader, so this both
            // narrows the candidate name and keeps the predicate
            // deterministic.
            if (is_subclass_of($fqn, \PhpParser\Node::class)) {
                $out[] = $fqn;
            }
        }
        // Admitted unconditionally: they are the documented blob
        // value-objects, and a cache must never silently void the
        // token stream or the comments leaf (see the const's evidence
        // list).
        foreach (self::BLOB_VALUE_CLASSES as $extra) {
            $out[] = $extra;
        }
        $out = array_values(array_unique($out));
        self::$parserClasses = $out;
        return $out;
    }

    /**
     * The combined allow-list used when reading a cached AST blob or a
     * worker frame: parser node classes (plus the enumerated value
     * objects) and phpdup's own value-objects, so a Block with an
     * embedded canonical AST round-trips intact.
     *
     * Contract stable since the caches were sealed (shell-S18 era):
     * AstCache/TokenCache consume {@see self::parserClasses()}, while
     * IndexStore, ClusterCache and WorkerPool's fork transport consume
     * this union.
     *
     * @return list<class-string>
     */
    public static function blockCacheClasses(): array
    {
        return array_values(array_unique(array_merge(self::blockObjectClasses(), self::parserClasses())));
    }

    /**
     * Every class, interface or trait name composer's autoloader knows
     * under the `PhpParser\` prefix — candidate names only. Nothing is
     * admitted on this list; {@see self::parserClasses()} decides
     * membership.
     *
     * @return list<string>
     */
    private static function discoverPhpParserClassNames(): array
    {
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
        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
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
                $out[] = $fqn;
            }
        }
        return $out;
    }
}
