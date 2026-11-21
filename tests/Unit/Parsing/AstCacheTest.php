<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parsing;

use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Phpdup\Persistence\SerializedClassAllowList;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\CacheBlob;

/**
 * Pins the engine-004/017/020 hardening of the AST disk cache:
 * length-framed blobs, recursive decode screening, the deep-AST write
 * guard, and private dir/file modes.
 */
final class AstCacheTest extends TestCase
{
    private string $tmp;
    private string $sourceFile;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup-ast-' . uniqid();
        mkdir($this->tmp . '/src', 0o775, true);
        $this->sourceFile = $this->tmp . '/src/Hello.php';
        file_put_contents($this->sourceFile, "<?php\nfunction greet(\$n) { if (\$n) { echo 'hi'; } }\n");
    }

    protected function tearDown(): void
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($rii as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($this->tmp . '/src');
        @rmdir($this->tmp);
    }

    public function testRoundTripsParsedStatements(): void
    {
        $stmts = $this->parseSource();
        $cache = $this->cache();
        $cache->put($this->sourceFile, $stmts);

        $loaded = $cache->get($this->sourceFile);
        $this->assertNotNull($loaded, 'framed round-trip must hit');
        $this->assertCount(count($stmts), $loaded);
        foreach ($loaded as $node) {
            $this->assertInstanceOf(Node\Stmt::class, $node);
        }
    }

    public function testContentChangeIsMiss(): void
    {
        $cache = $this->cache();
        $cache->put($this->sourceFile, $this->parseSource());

        file_put_contents($this->sourceFile, "<?php\nfunction greet(\$n) { if (\$n) { echo 'changed'; } }\n");
        $this->assertNull($cache->get($this->sourceFile));
    }

    public function testTruncatedBlobIsMiss(): void
    {
        $cache = $this->cache();
        $cache->put($this->sourceFile, $this->parseSource());
        $file = $this->onlyBlob();

        file_put_contents($file, substr((string)file_get_contents($file), 0, -8));
        $this->assertNull($cache->get($this->sourceFile), 'truncated frame must fail closed');
    }

    public function testAppendedGarbageIsMiss(): void
    {
        // unserialize() silently ignores trailing bytes — the length
        // header is what turns an appended blob into a miss (engine-017b).
        $cache = $this->cache();
        $cache->put($this->sourceFile, $this->parseSource());
        $file = $this->onlyBlob();

        file_put_contents($file, 'GARBAGE-GARBAGE', FILE_APPEND);
        $this->assertNull($cache->get($this->sourceFile));
    }

    public function testTopLevelDisallowedClassIsMiss(): void
    {
        $cache = $this->cache();
        $cache->put($this->sourceFile, $this->parseSource());

        file_put_contents($this->onlyBlob(), CacheBlob::frame(serialize(new \stdClass())));
        $this->assertNull($cache->get($this->sourceFile));
    }

    public function testNestedIncompleteInTypedSlotIsMissNotFatal(): void
    {
        // SEC nested.txt shape: a disallowed class planted in
        // Param::$default — a TYPED slot, so unserialize() itself raises
        // "Cannot assign __PHP_Incomplete_Class to property ..." before
        // any scanning loop can run. Pre-fix AstCache let that fatal out.
        $param = new Node\Param(new Node\Expr\Variable('x'), new Node\Scalar\Int_(1));
        $evil = self::spliceSegment(
            serialize([$param]),
            self::objectMarker(Node\Scalar\Int_::class),
            'O:10:"Evil_Class":1:{s:4:"evil";s:4:"data";}'
        );

        $threw = null;
        try {
            unserialize($evil, ['allowed_classes' => SerializedClassAllowList::parserClasses()]);
        } catch (\Throwable $e) {
            $threw = $e;
        }
        $this->assertInstanceOf(\TypeError::class, $threw, 'crafted payload must hit the typed-slot decode path');

        $cache = $this->cache();
        $cache->put($this->sourceFile, $this->parseSource());
        file_put_contents($this->onlyBlob(), CacheBlob::frame($evil));
        $this->assertNull($cache->get($this->sourceFile), 'must degrade to a miss, not propagate the TypeError');
    }

    public function testNestedIncompleteInUntypedSlotIsMiss(): void
    {
        // Second nested shape: disallowed class inside the untyped
        // stmts ARRAY slot — decode succeeds, only a recursive walk
        // catches it. The old top-level-only scan returned this poisoned
        // list as a hit (engine-017a).
        $fn = new Node\Stmt\Function_('f', [
            'stmts' => [new Node\Stmt\Expression(new Node\Scalar\Int_(1))],
        ]);
        $evil = self::spliceSegment(
            serialize([$fn]),
            self::objectMarker(Node\Stmt\Expression::class),
            'O:10:"Evil_Class":1:{s:4:"evil";s:4:"data";}'
        );

        // Prove the payload stays structurally valid AND that the top-level
        // screen alone would wave it through: decode yields a real Function_
        // whose nested stmt is an __PHP_Incomplete_Class.
        $decoded = unserialize($evil, ['allowed_classes' => SerializedClassAllowList::parserClasses()]);
        $this->assertIsArray($decoded);
        $this->assertInstanceOf(Node\Stmt\Function_::class, $decoded[0]);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded[0]->stmts[0]);

        $cache = $this->cache();
        $cache->put($this->sourceFile, $this->parseSource());
        file_put_contents($this->onlyBlob(), CacheBlob::frame($evil));
        $this->assertNull($cache->get($this->sourceFile), 'recursive screening must reject the nested incomplete class');
    }

    public function testNestedDisallowedElementIsMiss(): void
    {
        // engine-S23b r1 M-1 (mirror of TokenCacheTest — the shared
        // decode() arm must stay non-silent for both caches, and
        // engine-S24/S25 edit these callers next): a disallowed class
        // sitting directly in the top-level stmt LIST. Not a typed slot
        // (unserialize survives) and not hidden inside a Node (the tree
        // walk never enters it) — the stdClass restores as a property-
        // less __PHP_Incomplete_Class, which is neither a Stmt nor a
        // Node, so only the element instanceof arm rejects it.
        $stmt = new Node\Stmt\Expression(new Node\Scalar\Int_(1));
        $payload = serialize([$stmt, new \stdClass()]);

        // Counter-assert the discriminator: the raw decode yields a real
        // Stmt plus the incomplete, and the leaf screen ACCEPTS the
        // property-less incomplete — the instanceof arm is the only
        // screen standing between this shape and a poisoned HIT.
        $decoded = @unserialize($payload, ['allowed_classes' => SerializedClassAllowList::parserClasses()]);
        $this->assertIsArray($decoded);
        $this->assertInstanceOf(Node\Stmt::class, $decoded[0]);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded[1]);
        $this->assertTrue(
            CacheBlob::isScalarOnlyLeaf($decoded[1]),
            'the leaf screen accepts the incomplete element — the instanceof arm is the only screen that rejects it'
        );

        $cache = $this->cache();
        $cache->put($this->sourceFile, $this->parseSource());

        // Positive control: an honest [Stmt, Stmt] list must still HIT —
        // the arm guards the gate, it does not void the cache.
        file_put_contents($this->onlyBlob(), CacheBlob::frame(serialize([
            new Node\Stmt\Expression(new Node\Scalar\Int_(1)),
            new Node\Stmt\Expression(new Node\Scalar\Int_(2)),
        ])));
        $hit = $cache->get($this->sourceFile);
        $this->assertNotNull($hit, 'two honest statements must pass the element arm');
        $this->assertCount(2, $hit);

        file_put_contents($this->onlyBlob(), CacheBlob::frame($payload));
        $this->assertNull($cache->get($this->sourceFile), 'disallowed class in a list slot must be a miss, not a leak');
    }

    public function testDisallowedClassInAttributesSlotIsMiss(): void
    {
        // engine-S23a r1 M-1: Node::$attributes is not a sub-node list, so
        // the original walk skipped it. Reuse the splice trick to forge a
        // byte-valid blob whose incomplete class hides in an attribute
        // value — the screen must catch it, not return a poisoned hit.
        $fn = new Node\Stmt\Function_('f');
        $fn->setAttribute('hidden', new \stdClass());
        $evil = self::spliceSegment(
            serialize([$fn]),
            self::objectMarker(\stdClass::class),
            'O:10:"Evil_Class":1:{s:4:"evil";s:4:"data";}'
        );

        // Counter-assert: the payload passes the top-level screen with the
        // forged class smuggled live into the attribute slot.
        $decoded = unserialize($evil, ['allowed_classes' => SerializedClassAllowList::parserClasses()]);
        $this->assertIsArray($decoded);
        $this->assertInstanceOf(Node\Stmt\Function_::class, $decoded[0]);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded[0]->getAttribute('hidden'));

        $cache = $this->cache();
        $cache->put($this->sourceFile, $this->parseSource());
        file_put_contents($this->onlyBlob(), CacheBlob::frame($evil));
        $this->assertNull($cache->get($this->sourceFile), 'decode screening must walk the attributes slot too');
    }

    public function testCommentedSourceRoundTripsToHit(): void
    {
        // engine-S23a r2 HIGH-1: PhpParser\Comment — and its Doc subclass —
        // are NOT Nodes; they ride the parser's default `comments`
        // attribute as scalar-only value objects. The attributes walk must
        // accept them as allowed leaves, or every honest commented file
        // decodes to a permanent MISS while put() keeps writing blobs no
        // one can ever read (r1's over-widening premise "comments ride as
        // real Nodes" was false).
        $file = $this->tmp . '/src/Commented.php';
        file_put_contents($file, "<?php\n/**\n * Header docblock.\n */\n// leading line\nfunction greet(\$n) { /* inline */ echo 'hi'; }\n");

        // Counter-assert the premise on freshly parsed nodes: the top-level
        // function really carries the docblock + line comment, and a nested
        // statement really carries the inline comment.
        $stmts = $this->parseSource($file);
        $topComments = $stmts[0]->getAttributes()['comments'] ?? [];
        $this->assertCount(2, $topComments, 'parser must attach comments to the function node');
        $this->assertInstanceOf(\PhpParser\Comment\Doc::class, $topComments[0]);
        $this->assertInstanceOf(\PhpParser\Comment::class, $topComments[1]);

        $cache = $this->cache();
        $cache->put($file, $stmts);

        // get() never parses: a non-null return is the cache hit itself —
        // the differential the r2 review measured as MISS at 0051aff.
        $loaded = $cache->get($file);
        $this->assertNotNull($loaded, 'commented files must cache-hit, not silently void the AST cache');
        $this->assertCount(1, $loaded);

        // Comments survive the round-trip as real objects with text and
        // class intact — the contract DeclaredTwinAnalyzer relies on.
        $loadedComments = $loaded[0]->getAttributes()['comments'] ?? [];
        $this->assertCount(2, $loadedComments);
        $this->assertInstanceOf(\PhpParser\Comment\Doc::class, $loadedComments[0]);
        $this->assertInstanceOf(\PhpParser\Comment::class, $loadedComments[1]);
        $this->assertSame($topComments[0]->getText(), $loadedComments[0]->getText());
        $this->assertSame('// leading line', $loadedComments[1]->getText());
    }

    public function testCommentSmuggledDynamicPropertyFailsScreen(): void
    {
        // engine-S23a r3 LOW: the Comment leaf was accepted UNWALKED, so a
        // smuggled dynamic property — a live object here, a
        // __PHP_Incomplete_Class after a hostile decode — sailed past the
        // screen into a poisoned HIT. The leaf now self-verifies that its
        // own properties are null-or-scalar.
        $evil = new Node\Stmt\Function_('f');
        $evilComment = self::commentWithProps(['payload' => new \stdClass()]);
        $evil->setAttribute('comments', [$evilComment]);
        $this->assertFalse(
            CacheBlob::isCleanTree($evil),
            'object smuggled into a Comment property must fail the screen (reverting to a bare continue is the mutant)'
        );

        // Counter-assert the discriminator: the same Comment shape minus
        // the smuggled object stays clean, so the false above is caused
        // by the object, not by the Comment or its dynamic props per se.
        $honest = new Node\Stmt\Function_('f');
        $honestComment = self::commentWithProps(['note' => 'scalar', 'revision' => 7]);
        $honest->setAttribute('comments', [$honestComment]);
        $this->assertTrue(CacheBlob::isCleanTree($honest), 'null-or-scalar Comment properties must remain acceptable');

        // End-to-end over the real decode path: a blob carrying this
        // smuggle must degrade to a MISS, never return a poisoned hit.
        $cache = $this->cache();
        $cache->put($this->sourceFile, $this->parseSource());
        file_put_contents($this->onlyBlob(), CacheBlob::frame(serialize([$evil])));
        $this->assertNull($cache->get($this->sourceFile), 'smuggled Comment property must fail closed to a miss');
    }

    public function testCommentWithScalarDynamicPropertiesRoundTripsToHit(): void
    {
        // Acceptance half of the r3 hardening (HIT round-trip preserved):
        // Comment leaves whose properties are all null-or-scalar —
        // including scalar dynamic ones — must keep cache-HITting. The
        // screen may only reject smuggles, never void the honest r2
        // round-trip contract.
        $file = $this->tmp . '/src/ScalarProps.php';
        file_put_contents($file, "<?php\n// note\nfunction greet(\$n) { echo 'hi'; }\n");
        $stmts = $this->parseSource($file);
        $stmts[0]->setAttribute('comments', [self::commentWithProps(['author' => 'phpdup', 'revision' => 7], '// note')]);

        $cache = $this->cache();
        $cache->put($file, $stmts);

        $loaded = $cache->get($file);
        $this->assertNotNull($loaded, 'scalar-only Comment properties must stay cacheable');
        $loadedComments = $loaded[0]->getAttributes()['comments'] ?? [];
        $this->assertCount(1, $loadedComments);
        $this->assertInstanceOf(\PhpParser\Comment::class, $loadedComments[0]);
        $this->assertSame('// note', $loadedComments[0]->getText());
        $this->assertSame('phpdup', $loadedComments[0]->author, 'scalar dynamic prop survives the round-trip');
        $this->assertSame(7, $loadedComments[0]->revision);
    }

    public function testDeepAstIsNotCachedAndDoesNotThrow(): void
    {
        // ~4k node levels — inside the serialize() stack-guard crash zone
        // that took the whole run down at rc=255 (engine-004).
        $stmts = self::nestedArrayExpr(2000);
        $cache = $this->cache();
        $cache->put($this->sourceFile, $stmts);

        $this->assertSame([], glob($this->tmp . '/cache/*.cache') ?: []);
        $this->assertNull($cache->get($this->sourceFile));

        // The refusal must not poison the cache for other files.
        $other = $this->tmp . '/src/Small.php';
        file_put_contents($other, "<?php echo 1;\n");
        $cache->put($other, $this->parseSource($other));
        $this->assertNotNull($cache->get($other));
    }

    public function testNestedButShallowAstStillCaches(): void
    {
        // ~800 node levels — deep for synthetic code, but real code and
        // far below MAX_DEPTH: must stay cacheable (bound guard-rail, not
        // a blanket ban).
        $stmts = self::nestedArrayExpr(400);
        $cache = $this->cache();
        $cache->put($this->sourceFile, $stmts);

        $loaded = $cache->get($this->sourceFile);
        $this->assertNotNull($loaded);
        $this->assertCount(1, $loaded);
    }

    public function testFreshCacheDirIsPrivateAndBlobsOwnerOnly(): void
    {
        $umask = umask(0o002); // the shared-runner posture engine-020 measured
        $dir = $this->tmp . '/perm-cache';
        try {
            $cache = new AstCache($dir);
            clearstatcache();
            $this->assertSame(0o700, fileperms($dir) & 0o777, 'fresh cache dir must be private');

            $cache->put($this->sourceFile, $this->parseSource());
            $blobs = glob($dir . '/*.cache') ?: [];
            $this->assertCount(1, $blobs);
            clearstatcache();
            $this->assertSame(0o600, fileperms($blobs[0]) & 0o777, 'blobs must be owner-only under umask 002');
        } finally {
            umask($umask);
        }
    }

    public function testFramingPinsThePayloadLengthExactly(): void
    {
        $payload = "a:1:{s:5:'col:on';s:3:'x:y';}";
        $framed = CacheBlob::frame($payload);
        $this->assertSame($payload, CacheBlob::unframe($framed));
        $this->assertNull(CacheBlob::unframe($framed . 'X'));
        $this->assertNull(CacheBlob::unframe(substr($framed, 0, -1)));
        $this->assertNull(CacheBlob::unframe('x:payload'));
        $this->assertNull(CacheBlob::unframe('no colon here'));
    }

    // ---- helpers ----

    private function cache(): AstCache
    {
        return new AstCache($this->tmp . '/cache');
    }

    private function onlyBlob(): string
    {
        $files = glob($this->tmp . '/cache/*.cache') ?: [];
        $this->assertCount(1, $files, 'exactly one cache blob must exist for this fixture');
        return $files[0];
    }

    /** @return list<Node\Stmt> */
    private function parseSource(?string $file = null): array
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $stmts = $parser->parse((string)file_get_contents($file ?? $this->sourceFile));
        $this->assertIsArray($stmts);
        return $stmts;
    }

    /**
     * Iteratively build `[[[ ... [1] ... ]]]`-shaped expression stmts,
     * $levels Array_+ArrayItem node pairs deep (≈2·levels node depth).
     *
     * @return list<Node\Stmt>
     */
    private static function nestedArrayExpr(int $levels): array
    {
        $inner = new Node\Scalar\Int_(1);
        for ($i = 0; $i < $levels; $i++) {
            $inner = new Node\Expr\Array_([new Node\Expr\ArrayItem($inner)]);
        }
        return [new Node\Stmt\Expression($inner)];
    }

    /**
     * A real Comment carrying the given dynamic properties, materialised
     * by restoring a hand-extended serialize payload — the shape a
     * hostile blob smuggles props into a Comment leaf with. The
     * silence-operator hides PHP 8.2+'s dynamic-property creation
     * deprecation at fixture materialisation; the object graph the
     * screen inspects is unaffected.
     *
     * @param array<string,mixed> $dynamicProps
     */
    private static function commentWithProps(array $dynamicProps, string $text = '// smuggle probe'): \PhpParser\Comment
    {
        $base = serialize(new \PhpParser\Comment($text, 3, 0, 0, 3, 15, 5));
        if (!preg_match('/^O:(\d+):"PhpParser\\\\Comment":(\d+):\{(.*)\}$/s', $base, $m)) {
            self::fail('serialized Comment shape changed — update this fixture');
        }
        $extra = '';
        foreach ($dynamicProps as $key => $value) {
            $extra .= serialize((string)$key) . serialize($value);
        }
        $payload = 'O:' . $m[1] . ':"PhpParser\\Comment":'
            . ((int)$m[2] + count($dynamicProps)) . ':{' . $m[3] . $extra . '}';
        $built = @unserialize($payload, ['allowed_classes' => true]);
        if (!$built instanceof \PhpParser\Comment) {
            self::fail('extended payload did not restore a real Comment');
        }
        return $built;
    }

    /** Serialized-object prefix for a class, e.g. O:26:"PhpParser\...". */
    private static function objectMarker(string $fqcn): string
    {
        return 'O:' . strlen($fqcn) . ':"' . $fqcn . '"';
    }

    /**
     * Replace the full serialized segment that starts at $marker (brace
     * balanced) with $replacement. Loudly fails if the marker moves in a
     * future php-parser — the evil payloads must stay byte-valid.
     */
    private static function spliceSegment(string $blob, string $marker, string $replacement): string
    {
        $start = strpos($blob, $marker);
        if ($start === false) {
            self::fail("serialized blob lost its '$marker' segment — update the fixture");
        }
        $depth = 0;
        $end = -1;
        for ($i = $start, $n = strlen($blob); $i < $n; $i++) {
            if ($blob[$i] === '{') {
                $depth++;
            } elseif ($blob[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i + 1;
                    break;
                }
            }
        }
        if ($end <= $start) {
            self::fail("unbalanced segment for '$marker' — update the fixture");
        }
        return substr($blob, 0, $start) . $replacement . substr($blob, $end);
    }
}
