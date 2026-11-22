<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Persistence;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Persistence\SerializedClassAllowList;

final class SerializedClassAllowListTest extends TestCase
{
    public function testBlockObjectClassesContainsExpectedTypes(): void
    {
        $list = SerializedClassAllowList::blockObjectClasses();
        self::assertContains(\Phpdup\Clustering\Cluster::class, $list);
        self::assertContains(\Phpdup\Extraction\Block::class, $list);
        self::assertContains(\Phpdup\Refactor\Hole::class, $list);
        self::assertContains(\Phpdup\Util\LineRange::class, $list);
    }

    public function testParserClassesIncludesNodeClasses(): void
    {
        $list = SerializedClassAllowList::parserClasses();
        // Sanity-check that the enumeration found at least the most
        // common node classes — without these the cache load path
        // would always miss.
        self::assertNotEmpty($list);
        self::assertContains(\PhpParser\Node\Stmt\Function_::class, $list);
        self::assertContains(\PhpParser\Node\Expr\Variable::class, $list);
    }

    /**
     * engine-S23b interplay: TokenCache decodes its blob through
     * CacheBlob::decode(..., parserClasses(), Token::class). The element
     * class MUST be a member of the allow-list — if the enumeration ever
     * stops admitting it, every put() silently voids the cache (each
     * get() rejects its own honest payload at the instance screen).
     */
    public function testParserClassesAdmitsTokenCacheElementClass(): void
    {
        $list = SerializedClassAllowList::parserClasses();
        self::assertContains(\PhpParser\Token::class, $list);

        // Round-trip over the real decode path with the parser list:
        // an honest token stream survives screening.
        $payload = serialize([new \PhpParser\Token(T_OPEN_TAG, '<?php ', 1, 0)]);
        $decoded = \Phpdup\Parsing\CacheBlob::decode($payload, $list, \PhpParser\Token::class);
        self::assertNotNull($decoded, 'allow-list and TokenCache element contract must agree');
        self::assertInstanceOf(\PhpParser\Token::class, $decoded[0]);
    }

    public function testBlockCacheClassesIsUnion(): void
    {
        $list = SerializedClassAllowList::blockCacheClasses();
        self::assertContains(\Phpdup\Clustering\Cluster::class, $list);
        self::assertContains(\PhpParser\Node\Stmt\Function_::class, $list);
    }

    public function testUnserializeRejectsDisallowedClassAsIncomplete(): void
    {
        $allow = SerializedClassAllowList::blockObjectClasses();
        // Serialize an arbitrary class that is NOT in the allow-list.
        $blob = serialize(new \stdClass());
        $decoded = unserialize($blob, ['allowed_classes' => $allow]);
        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded);
    }

    /**
     * engine-S39 (php-dup-finder-engine-058) — the curation rule made
     * executable: inside the PhpParser family, membership is the
     * Node-subclass property, and the ONLY exemptions are the
     * enumerated value objects. If someone widens the prefix trust
     * again, or adds an extra without extending the published list,
     * this set equality fails.
     */
    public function testParserFamilyIsNodeSubclassesPlusEnumeratedExtras(): void
    {
        $nonNode = [];
        foreach (SerializedClassAllowList::parserClasses() as $class) {
            if (!is_subclass_of($class, Node::class)) {
                $nonNode[] = $class;
            }
        }
        $expected = SerializedClassAllowList::BLOB_VALUE_CLASSES;
        sort($nonNode);
        sort($expected);
        self::assertSame(
            $expected,
            $nonNode,
            'only the documented BLOB_VALUE_CLASSES may sit in the parser list without being Node subclasses'
        );
    }

    /**
     * engine-S39 pin on the wording: every admission is evidence-backed.
     * `PhpParser\Node\Attribute` (PHP-attribute nodes ride real blobs)
     * is admitted by the Node predicate — never a special case — while
     * the parser machinery that SHARES the prefix is refused.
     */
    public function testPrefixIsNoLongerAutoTrusted(): void
    {
        $list = SerializedClassAllowList::parserClasses();

        // Attribute/AttributeGroup are Nodes and ride real ASTs; they
        // must stay in through the predicate, not an extra.
        self::assertContains(\PhpParser\Node\Attribute::class, $list);
        self::assertContains(\PhpParser\Node\AttributeGroup::class, $list);
        self::assertTrue(is_subclass_of(\PhpParser\Node\Attribute::class, Node::class));

        // Machinery that the old whole-prefix admission waved in:
        // none of it can be a legitimate blob member.
        foreach ([
            \PhpParser\ParserFactory::class,
            \PhpParser\NodeVisitor\NameResolver::class,
            \PhpParser\NodeVisitor\NodeConnectingVisitor::class,
            \PhpParser\PrettyPrinter\Standard::class,
            \PhpParser\Internal\TokenStream::class,
            \PhpParser\ConstExprEvaluator::class,
            \PhpParser\Modifiers::class,
        ] as $machinery) {
            self::assertNotContains($machinery, $list, "$machinery shares the prefix but is not a Node");
        }

        // The concrete gadget reason: PhpParser\Error inherits
        // Exception's __wakeup. Its honest serialize must now decode
        // to the incomplete sentinel the caches treat as a miss.
        self::assertNotContains(\PhpParser\Error::class, $list);
        $decoded = unserialize(
            serialize(new \PhpParser\Error('smuggled')),
            ['allowed_classes' => $list]
        );
        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded);
    }

    /**
     * engine-S39 acceptance: no admitted class may carry an
     * unserialize-reachable magic hook — inherited ones included,
     * because `unserialize()` calls `__wakeup` through the inheritance
     * chain exactly as it calls `__unserialize` (which then supersedes
     * it). Any future admission that drags in, say, another Exception
     * subclass fails right here with the declaring class named.
     */
    public function testNoAllowedClassCarriesUnserializeHooks(): void
    {
        $hooks = ['__destruct', '__wakeup', '__unserialize'];
        $offenders = [];
        foreach (SerializedClassAllowList::blockCacheClasses() as $class) {
            if (!class_exists($class) && !interface_exists($class)) {
                self::fail("allow-list admits an unloadable class: $class");
            }
            $reflection = new \ReflectionClass($class);
            foreach ($hooks as $hook) {
                if ($reflection->hasMethod($hook)) {
                    $offenders[] = $class . '::' . $hook
                        . ' (declared by ' . $reflection->getMethod($hook)->getDeclaringClass()->getName() . ')';
                }
            }
        }
        self::assertSame([], $offenders, 'allowed classes must never expose unserialize hooks');
    }

    /**
     * engine-S39 — the tightening must not break legitimate decode:
     * the real blob families (AST with comment attributes, extracted
     * Blocks with their canonical node graphs) round-trip through the
     * curated list over the actual CacheBlob decode path. The
     * empirical class census below proves the enumeration is COMPLETE —
     * if a future php-parser starts riding a new non-Node class in a
     * blob, this test names it before any cache silently rots.
     */
    public function testRealBlobsDecodeOverTheCurationRule(): void
    {
        $source = "<?php\n/**\n * Header docblock.\n */\n#[SomeAttribute('x')]\n// leading line\nfunction greet(\$n) { /* inline */ echo \$n ? 'hi' : <<<TXT\nheredoc body\nTXT;\n }\n";

        $parser = new \Phpdup\Parsing\AstParser();
        $stmts = $parser->parseCode($source);
        self::assertIsArray($stmts);

        $astPayload = serialize($stmts);
        $decoded = \Phpdup\Parsing\CacheBlob::decode($astPayload, SerializedClassAllowList::parserClasses(), Node\Stmt::class);
        self::assertNotNull($decoded, 'commented+attributed AST must still decode under the curated list');

        // Comments survived as the real scalar-only leaves — the
        // contract DeclaredTwinAnalyzer and the wire scan rely on.
        // (NodeFinder visits the top-level statements too, which is
        // where the docblock + line comment ride on this fixture.)
        $comments = [];
        foreach ((new \PhpParser\NodeFinder())->find($decoded, static fn(Node $n): bool => true) as $commentBearing) {
            foreach ((array)($commentBearing->getAttributes()['comments'] ?? []) as $comment) {
                $comments[] = $comment;
            }
        }
        self::assertNotEmpty($comments, 'comment attributes must ride the decoded tree');
        foreach ($comments as $comment) {
            self::assertInstanceOf(\PhpParser\Comment::class, $comment);
            self::assertTrue(\Phpdup\Parsing\CacheBlob::isScalarOnlyLeaf($comment));
        }

        $blocks = (new \Phpdup\Extraction\BlockExtractor(minSize: 1))->extract('curation-probe.php', $stmts);
        self::assertNotEmpty($blocks);
        $blocks[0]->canonical = $stmts[0]; // the shape PreprocessWorker rows carry (mirrors WorkerPoolWireTest)
        $blockPayload = serialize($blocks);

        // The IndexStore/ClusterCache/WorkerPool leg: raw decode under
        // the union list must restore the graph whole — no blocked
        // class anywhere the wire scan can see (its documented
        // posture: public slots object-scanned, attribute slots proven
        // complete by the name census below).
        $restored = unserialize($blockPayload, ['allowed_classes' => SerializedClassAllowList::blockCacheClasses()]);
        self::assertIsArray($restored);
        self::assertNull(
            self::findIncompleteClass($restored),
            'Block graphs with canonical nodes must restore whole under the union list'
        );
        self::assertInstanceOf(\Phpdup\Extraction\Block::class, $restored[0]);
        self::assertInstanceOf(Node::class, $restored[0]->canonical);

        // The census itself: every object class the honest blobs carry
        // must be admitted, and every non-Node one must be an
        // enumerated extra (or a phpdup block object).
        $extras = SerializedClassAllowList::BLOB_VALUE_CLASSES;
        $blockClasses = SerializedClassAllowList::blockObjectClasses();
        foreach (array_merge(self::blobClassNames($astPayload), self::blobClassNames($blockPayload)) as $class) {
            self::assertContains(
                $class,
                SerializedClassAllowList::blockCacheClasses(),
                "$class rides an honest blob but left the allow-list"
            );
            if (!is_subclass_of($class, Node::class)) {
                self::assertTrue(
                    in_array($class, $extras, true) || in_array($class, $blockClasses, true),
                    "$class is a non-Node blob member outside the enumeration — widen BLOB_VALUE_CLASSES with evidence"
                );
            }
        }
    }

    /** @return list<string> every serialized object class name in a payload */
    private static function blobClassNames(string $payload): array
    {
        preg_match_all('/O:\d+:"([^"]+)"/', $payload, $m);
        /** @var list<string> $names */
        $names = array_values(array_unique($m[1]));
        return $names;
    }

    /**
     * Mirror of WorkerPool's blocked-class scan: walk public slots for
     * an __PHP_Incomplete_Class and report its original name.
     */
    private static function findIncompleteClass(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $name = self::findIncompleteClass($item);
                if ($name !== null) {
                    return $name;
                }
            }
            return null;
        }
        if (!is_object($value)) {
            return null;
        }
        if ($value instanceof \__PHP_Incomplete_Class) {
            /** @var array<string, mixed> $vars */
            $vars = get_object_vars($value);
            $name = $vars['__PHP_Incomplete_Class_Name'] ?? '<unknown>';
            return is_string($name) && $name !== '' ? $name : '<unknown>';
        }
        foreach (get_object_vars($value) as $propertyValue) {
            $name = self::findIncompleteClass($propertyValue);
            if ($name !== null) {
                return $name;
            }
        }
        return null;
    }
}
