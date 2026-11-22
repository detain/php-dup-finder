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

    // ── shell-S11 (php-dup-finder-shell-034): the merged list memo ───────
    //
    // shell-034: blockCacheClasses() re-ran array_merge + array_unique
    // over ~200 classes EVERY call (AstCache loads, the fork loop, the
    // index stores). The memoized form returns the identical list — for
    // arrays userland cannot see zval identity directly, so the pin is
    // the literal === re-comparison AND a reflection witness that the
    // memo slot is populated with exactly what the callers receive.

    public function testBlockCacheClassesIsMemoized(): void
    {
        $first  = SerializedClassAllowList::blockCacheClasses();
        $second = SerializedClassAllowList::blockCacheClasses();

        // The acceptance === assert: same elements, same order, every call.
        self::assertTrue($first === $second);

        // Structural witness that this is MEMOIZED, not merely
        // deterministic: the private slot holds the list after the call.
        $prop = new \ReflectionProperty(SerializedClassAllowList::class, 'blockCacheClasses');
        // No setAccessible(): no-op since PHP 8.1, deprecated since 8.5
        // (CI-85 leg tests/Unit/Persistence/SerializedClassAllowListTest.php:300).
        $slot = $prop->getValue();
        self::assertIsArray($slot, 'the memo slot must be populated after a call');
        self::assertTrue($slot === $first, 'callers must receive the memoized instance');

        // And the memo must equal the documented union, not some shorter
        // lazy stand-in.
        $expected = array_values(array_unique(array_merge(
            SerializedClassAllowList::blockObjectClasses(),
            SerializedClassAllowList::parserClasses(),
        )));
        self::assertSame($expected, $first);
    }

    // ── shell-S11 (php-dup-finder-shell-011): the bytes screen ───────────

    /**
     * The fuzz the acceptance demands: randomized graphs with AT MOST
     * one poison CLASS per payload (multiple distinct classes could be
     * reported in different first-hit orders by the two walks — a
     * refusal-name difference, not a verdict difference), over small
     * hermetic allow-lists. Both the byte screen and the graph screen
     * must answer with the same verdict AND the same name.
     */
    public function testBytesAndGraphScreensAgreeOnFuzzVectors(): void
    {
        mt_srand(0xC0FFEE);
        $allow = [\Phpdup\Util\LineRange::class];
        $lookalikes = [
            'O:8:"Evil":1:{s:1:"x";i:1;}',
            's:5:"hello";a:1:{i:0;O:6:"Evil_2":0:{}}',
            "nul\x00byte O:9:\"EvilNul\":0:{}",
            'E:12:"Missing:Evil";',
            'C:11:"EvilClosure":1:{}',
            '',
        ];

        for ($case = 0; $case < 240; $case++) {
            $poison = null; // one poison CLASS per payload, at most
            $value  = self::buildFuzzNode($lookalikes, $poison);
            $payload = serialize($value);

            $byteVerdict  = SerializedClassAllowList::findBlockedClassInPayload($payload, $allow);
            $decoded      = @unserialize($payload, ['allowed_classes' => $allow]);
            self::assertNotFalse($decoded, "fuzz case $case must be decodable — builder emitted a broken shape");
            $graphVerdict = SerializedClassAllowList::findIncompleteClass($decoded);

            // The wire gate's load-bearing invariant: a frame it calls
            // clean must decode with NO incomplete in the graph (skips
            // the walk safely), and a graph that does carry one must
            // never have been called clean (the walk's verdict the gate
            // skipped would have refused — a hole would be a security
            // regression, shell-011 acceptance (2)).
            $gateVerdict = SerializedClassAllowList::firstUnallowlistedClassHeader($payload, $allow);
            if ($gateVerdict === null) {
                self::assertNull($graphVerdict, "gate-cleared case $case must decode clean: " . $payload);
            }
            if ($graphVerdict !== null) {
                self::assertNotNull($gateVerdict, "incomplete-carrying case $case must not be gate-cleared: " . $payload);
            }

            self::assertSame(
                $graphVerdict,
                $byteVerdict,
                "verdict parity broke for case $case: " . $payload
            );
        }
    }

    /**
     * Random tree over scalars/arrays/allowed LineRange objects, mixing
     * in ONE foreign object class at a random depth (sometimes none,
     * sometimes nested three-plus levels inside allowed containers —
     * the acceptance's "blocked class nested 3 deep" shape).
     *
     * @param list<string> $lookalikes serialized-looking string bait
     */
    private static function buildFuzzNode(array $lookalikes, ?string &$poison): mixed
    {
        $roll = mt_rand(1, 100);
        if ($roll <= 22) {
            return mt_rand(1, 5) === 1
                ? $lookalikes[mt_rand(0, count($lookalikes) - 1)]
                : [mt_rand(-9999, 9999), (float) mt_rand(0, 100) / 3, true, null];
        }
        if ($roll <= 40) {
            return new \Phpdup\Util\LineRange(mt_rand(1, 50), mt_rand(51, 99));
        }
        if ($roll <= 88) {
            $out = [];
            $n   = mt_rand(0, 4);
            for ($k = 0; $k < $n; $k++) {
                $out['k' . $k] = self::buildFuzzNode($lookalikes, $poison);
            }
            return $out;
        }
        // The poison roll: one CLASS per payload (both arms always agree
        // on the first refusal name then); repeats of the roll reuse the
        // class, weaving the shared-DAG shapes the R: markers serialize to.
        if ($poison === null) {
            $poison = [
                \stdClass::class,
                \ArrayObject::class,
                \PhpParser\Error::class,
            ][mt_rand(0, 2)];
        }
        $cls = $poison;
        return $cls === \stdClass::class
            ? (object) ['evil' => new \stdClass()]
            : ($cls === \ArrayObject::class
                ? new \ArrayObject(['evil' => 1])
                : new \PhpParser\Error('evil'));
    }

    public function testBytesScreenNeverFalsePositivesOnSerializedLookalikeData(): void
    {
        $allow = SerializedClassAllowList::blockCacheClasses();
        // Bait that any naive /O:\d+:"([^"]+)"/ pre-filter would refuse:
        // poisoned-looking text inside string DATA, including inside
        // real node scalars and comment payloads.
        $payload = serialize([
            's:8:"EvilFake":0:{}',
            'key' => '/* O:17:"EvilGadgetClass":1:{s:4:"func";s:6:"system";} */',
            'nested' => ['deep' => ["\x00O:6:\"Zero\":1:{}\x00", 'E:12:"Missing:Evil";', 'C:13:"ClosurePoison":1:{}']],
        ]);
        self::assertNull(SerializedClassAllowList::findBlockedClassInPayload($payload, $allow));
    }

    public function testBytesScreenReportsDeepPoisonInsideNestedContainers(): void
    {
        // The literal acceptance shape "blocked class nested three deep":
        // three container levels stand between the root and the first
        // blocked O: — the graph screen caught this only by walking the
        // decoded tree; the bytes screen frames it without building the
        // tree. (The ALLOWED-CLASS container variants — poison parked in
        // a Block's public slot, or in a protected node attribute the old
        // walk could not see at all — get their own pins below and in
        // WorkerPoolTest's nested-fork regression.)
        $allow = SerializedClassAllowList::blockCacheClasses();
        $hidden = ['x' => [['y' => (object) ['evil' => new \ArrayObject()]]]];
        $payload = serialize(['rows' => ['block' => $hidden]]);
        self::assertSame(
            'stdClass',
            SerializedClassAllowList::findBlockedClassInPayload($payload, $allow),
            'a blocked class three containers deep must still be refused by name'
        );
    }

    public function testBytesScreenAgreesWithGraphOnProtectedSlotSmuggle(): void
    {
        // The posture the screen swap STRENGTHENS: poison parked in a
        // protected property (a node attribute) is invisible to the old
        // get_object_vars-from-foreign-scope walk but unmistakable in
        // the payload bytes. Acceptance is the byte arm refusing where
        // the graph arm goes blind — never the reverse.
        $node = new \PhpParser\Node\Stmt\Nop();
        $node->setAttribute('probe', new \ArrayObject(['evil' => true]));
        $payload = serialize(['stmts' => [$node]]);

        $byte = SerializedClassAllowList::findBlockedClassInPayload($payload, SerializedClassAllowList::blockCacheClasses());
        self::assertSame(\ArrayObject::class, $byte, 'the bytes screen sees attribute slots');

        $decoded = unserialize($payload, ['allowed_classes' => SerializedClassAllowList::blockCacheClasses()]);
        self::assertNull(
            SerializedClassAllowList::findIncompleteClass($decoded),
            'the public-slot graph walk stays blind here by design (its docblock) — the byte screen is what closes the gap'
        );
    }

    public function testBytesScreenStopsWithTheRootIgnoresTail(): void
    {
        // Parity with PHP: trailing bytes never decode into the graph,
        // so neither screen may invent a verdict from dead tail.
        $allow = SerializedClassAllowList::blockCacheClasses();
        $payload = serialize(['ok' => 1]) . serialize(new \stdClass());
        self::assertNull(SerializedClassAllowList::findBlockedClassInPayload($payload, $allow));
    }

    public function testBytesScreenUndecidablePayloadsFallBackCleanly(): void
    {
        // A payload the byte grammar does not frame (S: tag — PHP's own
        // decoder accepts it) must return the sentinel, NOT null — and
        // the documented chain (decode + graph screen) must answer.
        $allow = SerializedClassAllowList::blockCacheClasses();
        $count = 0;
        $exotic = str_replace('s:', 'S:', serialize(['n' => 'hi']), $count);
        self::assertGreaterThan(0, $count, 'the fixture must actually contain an s: header');
        self::assertSame(
            SerializedClassAllowList::SCAN_UNDECIDABLE,
            SerializedClassAllowList::findBlockedClassInPayload($exotic, $allow)
        );
        $decoded = @unserialize($exotic, ['allowed_classes' => $allow]);
        self::assertNotFalse($decoded, 'PHP decodes the exotic form — which is exactly why undecidable must not equal clean');
        self::assertNull(SerializedClassAllowList::findIncompleteClass($decoded));
    }

    public function testEnumTokensMatchTheGraphScreensVerdicts(): void
    {
        // PHP consults allowed_classes NOT AT ALL for enums: an existing
        // enum restores its real case whatever the list, a missing one
        // fails the WHOLE decode. Neither arm ever met an incomplete
        // through an E:, so both answer clean — and the byte arm must
        // frame E: without desyncing the walk.
        $allow = SerializedClassAllowList::blockCacheClasses();
        $payload = serialize(['e' => \Phpdup\Pipeline\Stage::Scanning]);
        self::assertNull(SerializedClassAllowList::findBlockedClassInPayload($payload, $allow));
        $decoded = unserialize($payload, ['allowed_classes' => $allow]);
        self::assertInstanceOf(\Phpdup\Pipeline\Stage::class, $decoded['e']);
        self::assertNull(SerializedClassAllowList::findIncompleteClass($decoded));

        // Missing-enum poison: PHP rejects the payload entirely — the
        // byte screen may answer null (nothing framed wrongly) and the
        // caller's corrupt-frame guard owns it, exactly as before S11.
        $ghost = 'a:1:{s:1:"e";E:15:"NopeMissing:Yes";}';
        self::assertFalse(@unserialize($ghost, ['allowed_classes' => $allow]));
    }

    public function testFlipMemoSwapsCorrectlyAcrossDifferentLists(): void
    {
        // The flip is memoized against the last list passed — interleaved
        // lists must each decide by THEIR OWN contents, never replay the
        // previous one's verdict.
        $payload = serialize(['k' => new \Phpdup\Util\LineRange(1, 2)]);
        $std = serialize(['k' => new \stdClass()]);

        self::assertNull(
            SerializedClassAllowList::findBlockedClassInPayload($payload, [\Phpdup\Util\LineRange::class]),
            'admitted under list A'
        );
        self::assertSame(
            'stdClass',
            SerializedClassAllowList::findBlockedClassInPayload($std, [\Phpdup\Util\LineRange::class]),
            'refused under list A'
        );
        self::assertSame(
            \Phpdup\Util\LineRange::class,
            SerializedClassAllowList::findBlockedClassInPayload($payload, []),
            'the same payload refuses under list B — the flip must have swapped'
        );
        self::assertNull(
            SerializedClassAllowList::findBlockedClassInPayload($payload, [\Phpdup\Util\LineRange::class]),
            'and back under list A — swapped again, verdict A restored'
        );
    }

    // ---------------------------------------------------------------
    // shell-S11 wire gate: firstUnallowlistedClassHeader()
    // ---------------------------------------------------------------

    public function testGateClearsHeaderlessAndFullyAllowedPayloads(): void
    {
        $allow = SerializedClassAllowList::blockCacheClasses();
        foreach ([
            'N;',
            'i:42;',
            'b:1;',
            'd:1.5;',
            's:5:"hello";',
            'a:2:{s:1:"a";i:1;s:1:"b";s:3:"xyz";}',
            serialize(['__done' => true]),
            serialize(['__error' => 'boom']),
            serialize(new \Phpdup\Util\LineRange(1, 2)),
            serialize(['n' => new \PhpParser\Node\Stmt\Nop()]),
        ] as $payload) {
            self::assertNull(
                SerializedClassAllowList::firstUnallowlistedClassHeader($payload, $allow),
                'gate must clear an allowed/honest payload: ' . addcslashes(substr($payload, 0, 48), "\0..\37")
            );
        }
    }

    public function testGateNamesOffListHeadersShallowAndDeep(): void
    {
        $allow = SerializedClassAllowList::blockCacheClasses();
        self::assertSame(
            'stdClass',
            SerializedClassAllowList::firstUnallowlistedClassHeader(serialize(['x' => new \stdClass()]), $allow)
        );
        $deep = serialize(['rows' => ['block' => ['x' => ['y' => ['z' => new \ArrayObject()]]]]]);
        self::assertSame(
            'ArrayObject',
            SerializedClassAllowList::firstUnallowlistedClassHeader($deep, $allow),
            'a header nested five levels down is still spelled literally by the serializer'
        );
        self::assertSame(
            'PhpParser\Error',
            SerializedClassAllowList::firstUnallowlistedClassHeader(serialize(['e' => new \PhpParser\Error('x')]), $allow),
            'the engine-S39 forbidden non-Node family routes too'
        );
    }

    public function testGateRoutesCaseShuffledAndForgedHeaders(): void
    {
        $allow = SerializedClassAllowList::blockCacheClasses();
        self::assertSame(
            'stdclass',
            SerializedClassAllowList::firstUnallowlistedClassHeader('a:1:{i:0;O:8:"stdclass":0:{}}', $allow),
            'case-shuffled name misses the byte-exact flip — routes to the walk'
        );
        self::assertSame(
            'A',
            SerializedClassAllowList::firstUnallowlistedClassHeader('O:9:"A"nother":0:{}', $allow),
            'declared length disagrees with the capture — hand-forged framing'
        );
        // S11R1-2 discriminating pins: each payload spells an ALLOWED,
        // resolvable capture, so the flip and unresolvable arms cannot
        // route it — only ONE of the two header-arithmetic guards can.
        // Without the arm it pins, the gate would wave the forged
        // header through as clean (the routing authority is the arm,
        // not the list miss).
        self::assertSame(
            'Phpdup\Extraction\Block',
            SerializedClassAllowList::firstUnallowlistedClassHeader(
                'O:24:"Phpdup\Extraction\Block":0:{}',
                $allow
            ),
            'allowed name with a one-byte-longer declared length — the strlen-equality arm alone routes it'
        );
        self::assertSame(
            'Phpdup\Extraction\Block',
            SerializedClassAllowList::firstUnallowlistedClassHeader(
                'O:0000000000000000000023:"Phpdup\Extraction\Block":0:{}',
                $allow
            ),
            'zero-padded 22-digit declaration whose int value equals the name length — the >18 digit-string arm alone routes it'
        );
        self::assertSame(
            'Short',
            SerializedClassAllowList::firstUnallowlistedClassHeader('O:99999999999999999999999:"Short":0:{}', $allow),
            'oversized digit string never wraps into a match'
        );
        self::assertNotNull(
            SerializedClassAllowList::firstUnallowlistedClassHeader('O:11:"Phpdup\N' . chr(0) . 'X":0:{}', $allow),
            'NUL inside a header name cannot hide behind the [^"]* capture'
        );
    }

    public function testGateRoutesEnumTokensToTheWalk(): void
    {
        $allow = SerializedClassAllowList::blockCacheClasses();
        $payload = serialize(['e' => \Phpdup\Pipeline\Stage::Scanning]);
        self::assertSame(
            \Phpdup\Pipeline\Stage::class . ':Scanning',
            SerializedClassAllowList::firstUnallowlistedClassHeader($payload, $allow),
            'E: names carry the case suffix and never match the plain class — slow path (baseline-consistent: real enums decode and the walk yields them)'
        );
    }

    public function testGateClearsResolvableNonInstantiableButRoutesUnlistedGhosts(): void
    {
        // An allowed+resolvable abstract class name CANNOT arrive as an
        // incomplete (PHP throws while instantiating — both chains
        // behave identically), so the gate may clear it. A name that is
        // ON the list but unresolvable must route: it decodes to an
        // incomplete exactly like an unlisted one would.
        $gate = new \ReflectionMethod(SerializedClassAllowList::class, 'firstUnallowlistedClassHeader');
        $abstractHeader = sprintf('O:%d:"%s":0:{}', strlen(\PhpParser\NodeAbstract::class), \PhpParser\NodeAbstract::class);
        $ghostHeader    = sprintf('O:%d:"%s":0:{}', strlen('Phpdup\NoSuch\Ghost'), 'Phpdup\NoSuch\Ghost');
        self::assertNull(
            $gate->invoke(null, $abstractHeader, [\PhpParser\NodeAbstract::class]),
            'abstract but resolvable: no incomplete possible — cleared'
        );
        self::assertSame(
            'Phpdup\NoSuch\Ghost',
            $gate->invoke(null, $ghostHeader, ['Phpdup\NoSuch\Ghost']),
            'allowed-but-missing class routes despite being on the list'
        );
        // Memo identity: two interleaved lists each decide by their own
        // resolvability set (mirrors the flip memo pin above).
        self::assertNull(
            $gate->invoke(null, $abstractHeader, [\PhpParser\NodeAbstract::class]),
            'back to list A — unresolvable memo must have swapped'
        );
    }

    public function testGateTreatsStringBodiesAsPotentialSuspectsNotFalseAcquittals(): void
    {
        // Lookalike text inside a string body may trip the header regex
        // (the gate does not parse framing to know otherwise) — routing
        // to the walk is the SAFE direction; what must never happen is
        // a gate-cleared frame carrying a real incomplete, pinned by
        // the fuzz invariant above.
        $allow = SerializedClassAllowList::blockCacheClasses();
        $bait = serialize(['note' => 'O:8:"stdClass":0:{}']);
        self::assertSame(
            'stdClass',
            SerializedClassAllowList::firstUnallowlistedClassHeader($bait, $allow),
            'bait routes to the authoritative walk (which will clear it)'
        );
    }

    // ------------------------------------------------------------------
    // shell-S39 — trust-posture roll-up (php-dup-finder-shell-052)
    // ------------------------------------------------------------------

    /**
     * Leg (a), the downstream half: engine-S39 curated the list; this
     * pins that the WHOLE production decode surface actually routes
     * through it. Three assertions, one per way the guard could rot:
     *
     *  1. COMPLETENESS — the exact census of real `unserialize(` call
     *     sites in src/ (tokenized, so docblocks and quoted text like
     *     WorkerPool's error string never count). A fifth surface — a
     *     new store, a debug dump, a vendored shortcut — must appear
     *     here before it ships.
     *  2. LINEAGE — every listed site's allowed-classes expression is
     *     sourced from THIS class: the two framed stores and the wire
     *     pool name `SerializedClassAllowList::` at the statement;
     *     CacheBlob is the parameterized choke point, and every caller
     *     of `CacheBlob::decode()` in src/ passes a curated list.
     *  3. NO WIDENING — nothing in src/ ever spells
     *     `allowed_classes => true`.
     */
    public function testEveryProductionDecodeSiteRoutesThroughThisAllowList(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        self::assertDirectoryExists($root);
        $files = self::s39PhpFiles($root);

        // Comment-stripped sources: docblock prose must never satisfy a
        // lineage string, and must never trip the no-widening census —
        // SerializedClassAllowList's own header quotes the `true` shape
        // it exists to forbid.
        $clean = [];
        foreach ($files as $rel => $path) {
            $clean[$rel] = self::s39StripComments((string) file_get_contents($path));
        }

        $sites = [];
        foreach ($clean as $rel => $source) {
            $count = self::s39CountUnserializeCalls($source);
            if ($count > 0) {
                $sites[$rel] = $count;
            }
        }
        ksort($sites);
        self::assertSame([
            'Parallel/WorkerPool.php'      => 1,
            'Parsing/CacheBlob.php'        => 1,
            'Persistence/ClusterCache.php' => 1,
            'Persistence/IndexStore.php'   => 1,
        ], $sites, 'the unserialize surface is exactly these four sites — a new one is a trust decision, not a refactor detail');

        // Lineage: the sites that pass the list inline spell it from here.
        foreach (['Persistence/IndexStore.php', 'Persistence/ClusterCache.php'] as $rel) {
            self::assertStringContainsString(
                "'allowed_classes' => SerializedClassAllowList::blockCacheClasses()",
                $clean[$rel],
                "{$rel} must decode with the curated union"
            );
        }
        self::assertStringContainsString('$allowList = SerializedClassAllowList::blockCacheClasses();', $clean['Parallel/WorkerPool.php']);
        self::assertStringContainsString("'allowed_classes' => \$allowList", $clean['Parallel/WorkerPool.php']);

        // Lineage through the choke point: CacheBlob::decode takes the
        // list as a parameter — so every CALLER in src/ must name a
        // curated expression there (both caches pass parserClasses()).
        $decodeCallers = [];
        foreach ($clean as $rel => $source) {
            $hits = (int) preg_match_all(
                '/CacheBlob::decode\(\s*[^,]+,\s*SerializedClassAllowList::\w+\(\)/',
                $source
            );
            if ($hits > 0) {
                $decodeCallers[$rel] = $hits;
            }
        }
        ksort($decodeCallers);
        self::assertSame([
            'Parsing/AstCache.php'   => 1,
            'Parsing/TokenCache.php' => 1,
        ], $decodeCallers, 'CacheBlob::decode has exactly two curated callers in src/');

        // No widening anywhere.
        $widened = 0;
        foreach ($clean as $source) {
            $widened += (int) preg_match_all("/allowed_classes'\\s*=>\\s*true/", $source);
        }
        self::assertSame(0, $widened, "no src/ site may hand unserialize() the whole autoloader");
    }

    /** @return array<string,string> rel-path-under-src => absolute path */
    private static function s39PhpFiles(string $root): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $out[str_replace($root . '/', '', $file->getPathname())] = $file->getPathname();
            }
        }
        ksort($out);
        self::assertNotEmpty($out, 'src/ must exist for the decode-surface census');
        return $out;
    }

    /**
     * Rebuild the source without comments (each replaced by one
     * newline), so the string heuristics below only ever see code.
     */
    private static function s39StripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $t) {
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                $out .= "\n";
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    /**
     * Tokenized count of REAL unserialize() invocations — comment,
     * docblock and string-quoted mentions (WorkerPool's error text)
     * never count.
     */
    private static function s39CountUnserializeCalls(string $source): int
    {
        $count = 0;
        $tokens = token_get_all($source);
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING || strcasecmp($t[1], 'unserialize') !== 0) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if ($tokens[$j] === '(') {
                    $count++;
                }
                break;
            }
        }
        return $count;
    }
}
