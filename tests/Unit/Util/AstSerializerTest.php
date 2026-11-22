<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Util;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\Delimiters;

/**
 * Tests for AstSerializer node-count invariants.
 *
 * Verifies:
 * - subtreeSize(node) = 1 + sum(children subtreeSize) for all nodes
 * - nodeCount() is idempotent (same result on repeated calls)
 */
final class AstSerializerTest extends TestCase
{
    private static AstParser $parser;

    public static function setUpBeforeClass(): void
    {
        self::$parser = new AstParser();
    }

    /**
     * Recursively computes the expected subtree size of a node using the
     * bottom-up definition: size = 1 + sum(child sizes).
     */
    private static function expectedSubtreeSize(Node $node): int
    {
        $size = 1;
        foreach ($node->getSubNodeNames() as $sub) {
            $val = $node->$sub;
            if ($val instanceof Node) {
                $size += self::expectedSubtreeSize($val);
            } elseif (is_array($val)) {
                foreach ($val as $v) {
                    if ($v instanceof Node) {
                        $size += self::expectedSubtreeSize($v);
                    }
                }
            }
        }
        return $size;
    }

    /**
     * Collects all nodes in the tree (pre-order) so we can verify the
     * invariant for every node individually.
     *
     * @return list<Node>
     */
    private static function collectAllNodes(Node $node): array
    {
        $nodes = [$node];
        foreach ($node->getSubNodeNames() as $sub) {
            $val = $node->$sub;
            if ($val instanceof Node) {
                foreach (self::collectAllNodes($val) as $n) {
                    $nodes[] = $n;
                }
            } elseif (is_array($val)) {
                foreach ($val as $v) {
                    if ($v instanceof Node) {
                        foreach (self::collectAllNodes($v) as $n) {
                            $nodes[] = $n;
                        }
                    }
                }
            }
        }
        return $nodes;
    }

    public function testFloatLiteralsSerializeAsCanonicalIeeeBytes(): void
    {
        // phpver-fix: float literals used to ride the ini- and version-
        // dependent decimal cast ('f:1.5', and 'f:1' vs 'f:1.0' style
        // drift), moving every structuralHash that covered a float. The
        // token is now the big-endian IEEE-754 hex — permanently pinned
        // so any future re-decimalization fails loudly.
        $stmts = self::$parser->parseCode('<?php function f() { return 1.5; }');
        $this->assertNotNull($stmts);
        $floatTokens = array_values(array_filter(
            AstSerializer::tokens($stmts[0]),
            static fn(string $t): bool => str_contains($t, 'f:F'),
        ));
        $this->assertSame(['Scalar_Float_|f:F3FF8000000000000'], $floatTokens);

        // Whole-valued floats lose their decimal entirely: 1.0 is byte 3FF0…
        $whole = self::$parser->parseCode('<?php function g() { return 1.0; }');
        $this->assertNotNull($whole);
        $this->assertSame(
            ['Scalar_Float_|f:F3FF0000000000000'],
            array_values(array_filter(
                AstSerializer::tokens($whole[0]),
                static fn(string $t): bool => str_contains($t, 'f:F'),
            )),
        );
    }

    public function testNodeCountInvariant(): void
    {
        // Parse a small but non-trivial PHP snippet containing:
        // - a function definition with a return statement
        // - an assignment expression
        $code = '<?php function foo() { return 42; } $x = "hello";';
        $stmts = self::$parser->parseCode($code);
        $root = $stmts[0];

        $allNodes = self::collectAllNodes($root);

        // For each node, verify: AstSerializer::nodeCount(node) == expectedSubtreeSize(node)
        foreach ($allNodes as $node) {
            $actualCount = AstSerializer::nodeCount($node);
            $expectedCount = self::expectedSubtreeSize($node);
            $this->assertSame(
                $expectedCount,
                $actualCount,
                sprintf(
                    'NodeCount invariant violated for %s: expected %d, got %d',
                    $node::class,
                    $expectedCount,
                    $actualCount
                )
            );
        }
    }

    public function testNodeCountConsistency(): void
    {
        // Parse a function with multiple statements
        $code = '<?php function bar() { $x = 1; return $x + 2; }';
        $stmts = self::$parser->parseCode($code);
        $root = $stmts[0];

        // Call nodeCount 5 times and verify stable result
        $counts = [];
        for ($i = 0; $i < 5; $i++) {
            $counts[] = AstSerializer::nodeCount($root);
        }

        $uniqueCounts = array_unique($counts);
        $this->assertCount(1, $uniqueCounts, sprintf(
            'nodeCount() returned inconsistent results: %s',
            implode(', ', array_map('strval', $counts))
        ));
    }

    public function testNodeCountSurvivesPathologicalNestingDepth(): void
    {
        // engine-004: nodeCount() used to recurse per descendant node, so a
        // legal-but-pathological nesting depth (the parser itself survives
        // it) blew the worker stack mid-count. The iterative count must
        // return the exact total without coming near the call-stack limit.
        $levels = 2000;
        $current = new Scalar\Int_(1);
        $built = 1;
        for ($i = 0; $i < $levels; ++$i) {
            $current = new Expr\Array_([new Expr\ArrayItem($current)]);
            $built += 2; // Array_ + ArrayItem
        }
        $root = new Node\Stmt\Expression($current);

        self::assertSame($built + 1, AstSerializer::nodeCount($root));
    }

    // ------------------------------------------------------------------
    // engine-S34a — prefix-free stream (php-dup-finder-engine-037)
    // ------------------------------------------------------------------

    /** @return list<string> */
    private function streamOf(string $code, string $mode = 'strict'): array
    {
        $stmts = self::$parser->parseCode($code);
        self::assertNotNull($stmts, 'fixture code must parse');
        $blocks = (new BlockExtractor(minSize: 1))->extract('t.php', $stmts);
        self::assertNotEmpty($blocks, 'fixture must yield a block');
        (new Normalizer($mode))->normalize($blocks[0]);
        return AstSerializer::tokens($blocks[0]->canonical);
    }

    private function hashOf(string $code, string $mode = 'strict'): string
    {
        $stmts = self::$parser->parseCode($code);
        self::assertNotNull($stmts, 'fixture code must parse');
        $blocks = (new BlockExtractor(minSize: 1))->extract('t.php', $stmts);
        self::assertNotEmpty($blocks, 'fixture must yield a block');
        (new Normalizer($mode))->normalize($blocks[0]);
        return (new SubtreeHasher())->hash($blocks[0]->canonical);
    }

    /**
     * Independent left-to-right decoder for the framed stream grammar —
     * the oracle the uniqueness properties are fenced against. A
     * FRAME_MARK byte may only open a `MARK len ':' body` frame (safe
     * values never contain it), so a reader skews zero knowledge of the
     * node grammar: read bytes, on MARK take the decimal length and skip
     * that many body bytes verbatim, on $sep flush the current token.
     *
     * @return list<string>
     */
    private static function decodeStream(string $stream, string $sep): array
    {
        $tokens = [];
        $current = '';
        $i = 0;
        $n = strlen($stream);
        while ($i < $n) {
            $byte = $stream[$i];
            if ($byte === AstSerializer::FRAME_MARK) {
                $colon = strpos($stream, ':', $i + 1);
                self::assertNotFalse($colon, 'frame marker without a length colon');
                $len = (int) substr($stream, $i + 1, $colon - $i - 1);
                $bodyEnd = $colon + 1 + $len;
                self::assertLessThanOrEqual(strlen($stream), $bodyEnd, 'frame body overruns the stream');
                $current .= substr($stream, $i, $bodyEnd - $i);
                $i = $bodyEnd;
                continue;
            }
            if ($byte === $sep) {
                $tokens[] = $current;
                $current = '';
                ++$i;
                continue;
            }
            $current .= $byte;
            ++$i;
        }
        $tokens[] = $current;
        return $tokens;
    }

    public function testCleanScalarsRideTheStreamByteIdentical(): void
    {
        // The no-move law of the framing leg: a value free of the framing
        // bytes ({FRAME_MARK}, TOKEN_JOIN, NGRAM_SEP — plus ';' inside L:
        // segments) passes through unframed, so every honest corpus keeps
        // its pre-framing stream bytes. The absolute digest below is the
        // structural hash captured from the pre-framing build (engine-S34a
        // baseline probe on the pristine extract); drifting it here means
        // the framing changed byte identity of clean code — a cache/golden
        // mass-migration this half must never cause.
        $tokens = $this->streamOf('<?php function c() { $s = "notify@x.org"; $n = send($s, 7); return $n > 0 ? "ok" : $s; }');
        $this->assertContains('Scalar_String_|s:notify@x.org', $tokens);
        $this->assertContains('Scalar_String_|s:ok', $tokens);
        $this->assertSame('ab38abd589f9919e27634084903d1220', $this->hashOf(
            '<?php function c() { $s = "notify@x.org"; $n = send($s, 7); return $n > 0 ? "ok" : $s; }'
        ));
    }

    public function testControlBytesInScalarsAreLengthFramed(): void
    {
        // engine-037 mechanism pin: a literal carrying the stream joiner
        // is framed `MARK len ':' raw` inside the s: payload…
        $forged = $this->streamOf('<?php function g() { return "a\x1fb"; }');
        $needle = 'Scalar_String_|s:' . AstSerializer::FRAME_MARK . '3:a' . Delimiters::TOKEN_JOIN . 'b';
        $this->assertContains($needle, $forged);

        // …and so is a literal carrying the n-gram separator or the mark
        // itself (the mark forces framing precisely so a frame can never
        // be confused with raw text).
        $gramForged = $this->streamOf('<?php function g() { return "a\x1eb"; }');
        $this->assertContains('Scalar_String_|s:' . AstSerializer::FRAME_MARK . '3:a' . Delimiters::NGRAM_SEP . 'b', $gramForged);

        $mark = $this->streamOf('<?php function g() { return "a' . AstSerializer::FRAME_MARK . 'b"; }');
        $this->assertContains('Scalar_String_|s:' . AstSerializer::FRAME_MARK . '3:a' . AstSerializer::FRAME_MARK . 'b', $mark);

        // The framed stream still decodes to exactly its token list.
        $decoded = self::decodeStream(implode(Delimiters::TOKEN_JOIN, $forged), Delimiters::TOKEN_JOIN);
        $this->assertSame($forged, $decoded);
    }

    public function testInterpolatedSegmentsTerminateUnambiguously(): void
    {
        // ';' terminates an L: segment, so a part whose text contains ';'
        // must be framed; a ';' free part rides byte-identical.
        $withSemi = $this->streamOf('<?php function i() { return "a;X{$v}"; }');
        $framed = 'is:L:' . AstSerializer::FRAME_MARK . '3:a;X;X;';
        $this->assertContains('Scalar_InterpolatedString|' . $framed, $withSemi);
        $this->assertSame(
            $withSemi,
            self::decodeStream(implode(Delimiters::TOKEN_JOIN, $withSemi), Delimiters::TOKEN_JOIN)
        );

        $plain = $this->streamOf('<?php function j() { return "pre{$v}"; }');
        $this->assertContains('Scalar_InterpolatedString|is:L:pre;X;', $plain);
    }

    public function testAppendedStringAttributesAreFramed(): void
    {
        // walk() appends raw scalar sub-node attributes after '#'; the
        // InlineHTML value is free text and takes the same frame.
        $tokens = AstSerializer::tokens(new Node\Stmt\InlineHTML("a\x1Fb"));
        $this->assertSame(
            ['Stmt_InlineHTML#' . AstSerializer::FRAME_MARK . '3:a' . Delimiters::TOKEN_JOIN . 'b'],
            $tokens
        );
    }

    public function testPd07CollisionPairNowHashesDifferently(): void
    {
        // PDDETECT-07: a one-argument call whose literal splices the
        // forged tokens `)`, `Arg#false#false(` and `Scalar_String_|s:b`
        // joined to byte-identical streams with a real two-argument call
        // (baseline probe at HEAD: both hash 71302f1d9c36d43cc5267b921b3bf4df).
        $twoArg = '<?php function q() { e("a", "b"); return 1; }';
        $forged = '<?php function q() { e("a\x1f)\x1fArg#false#false(\x1fScalar_String_|s:b"); return 1; }';

        // The forged literal's bytes frame ⇒ the streams part company.
        $this->assertNotSame($this->hashOf($twoArg), $this->hashOf($forged));

        // The clean side is byte-frozen (unframed): its digest is the one
        // the pre-framing build produced for it, too.
        $this->assertSame('71302f1d9c36d43cc5267b921b3bf4df', $this->hashOf($twoArg));

        // And the forgery attempt is visible in the stream: the spliced
        // bytes survive inside a length frame, never as token structure.
        $forgedTokens = $this->streamOf($forged);
        $joined = implode(Delimiters::TOKEN_JOIN, $forgedTokens);
        $this->assertSame($forgedTokens, self::decodeStream($joined, Delimiters::TOKEN_JOIN));
    }

    public function testFuzz04CollisionPairNowHashesDifferently(): void
    {
        // ENGINE-FUZZ-04: the two-argument variant over an Int token —
        // f("a", 1) vs f(<forge of ")" + "Arg#false#false(" + "Scalar_Int_|i:1">).
        // Baseline probe at HEAD: identical names ⇒ both hash
        // fb3d807498ada2cfc99bb2fbc753e27a.
        $real = '<?php function w() { f("a", 1); }';
        $forge = '<?php function w() { f("a\x1F)\x1FArg#false#false(\x1FScalar_Int_|i:1"); }';

        $this->assertNotSame($this->hashOf($real), $this->hashOf($forge));
        $this->assertSame('fb3d807498ada2cfc99bb2fbc753e27a', $this->hashOf($real));
    }

    public function testDistinctTokenListsJoinToDistinctStreams(): void
    {
        // The prefix-free property, fenced over the serializer's image:
        // build a corpus of function blocks whose literals, interpolated
        // parts, variable names and identifiers are drawn from an
        // adversarial alphabet (stream joiners, frame mark, '|', '#',
        // ':', ';', digits+colon strings that imitate a frame header,
        // and UTF-8 edge sequences). Two distinct token lists must never
        // join to the same stream at either the TOKEN_JOIN or the
        // NGRAM_SEP layer, and every stream must decode back to exactly
        // its token list.
        $alphabet = [
            'a', 'ok', '', 'notify@x.org', '__V1', '__CALL_send',
            'a' . "\x1E" . 'b', 'a' . "\x1F" . 'b', 'a' . AstSerializer::FRAME_MARK . 'b',
            'x)y', 'Arg#false#false(', '|s:b', 'a;b', 'a:L:x', ';L:x', 'L:a;X;',
            "\x1E", "\x1F", AstSerializer::FRAME_MARK, '|', '#', ':', ';', '3:ab',
            '5:' . "\x1E" . 'x', '2:' . "\x1F", '€', 'é', "\xC3\x28", "\xF0\x9F\x98\x80x",
            'a' . "\x1D" . '3:a', '0:', 'x' . "\x1F" . ')',
        ];
        mt_srand(20261122); // deterministic corpus
        $quoteChars = "\0..\x1F\x7F" . chr(92) . chr(34) . chr(39) . chr(36) . chr(123) . chr(125);
        $interpChars = $quoteChars; // same set: '$' '{' '}' must not leak either

        /** @var list<array{name:string,tokens:list<string>}> $corpus */
        $corpus = [];
        for ($i = 0; $i < 260; $i++) {
            $s1 = $alphabet[mt_rand(0, count($alphabet) - 1)];
            $s2 = $alphabet[mt_rand(0, count($alphabet) - 1)];
            $kind = mt_rand(0, 3);
            if ($kind === 0) {
                $code = sprintf(
                    '<?php function %s() { return "%s"; }',
                    'f' . $i . ($s1 === '' ? '' : '_'),
                    addcslashes($s1, $quoteChars) // keep it a single literal
                );
            } elseif ($kind === 1) {
                $code = sprintf(
                    '<?php function %s() { return "%s{$v}%s"; }',
                    'f' . $i,
                    addcslashes($s1, $interpChars),
                    addcslashes($s2, $interpChars)
                );
            } elseif ($kind === 2) {
                // Non-UTF-8-safe names cannot be parsed, so drive the
                // name/id channels synthetically instead.
                $call = new Expr\FuncCall(new Node\Name(preg_replace('/[^\x20-\x7E]/', '', $s1) ?: 'fz'));
                $stmt = new Node\Stmt\Expression(new Expr\Assign(
                    new Expr\Variable(preg_replace('/[^\x20-\x7E]/', '', $s2) ?: 'vz'),
                    new Scalar\String_($s1)
                ));
                $corpus[] = ['name' => "syn$i", 'tokens' => AstSerializer::tokens(new Node\Stmt\Expression($call))];
                $corpus[] = ['name' => "synv$i", 'tokens' => AstSerializer::tokens($stmt)];
                continue;
            } else {
                $code = sprintf(
                    '<?php function %s() { $h = "%s"; $k = "%s"; return $h . $k; }',
                    'f' . $i,
                    addcslashes($s1, "\0..\x1F\x7F\\\"'"),
                    addcslashes($s2, "\0..\x1F\x7F\\\"'")
                );
            }
            $stmts = self::$parser->parseCode($code);
            if ($stmts === null) {
                continue; // escape-sequence round trip not re-parseable — corpus keeps going
            }
            $blocks = (new BlockExtractor(minSize: 1))->extract('t.php', $stmts);
            if ($blocks === []) {
                continue;
            }
            (new Normalizer('strict'))->normalize($blocks[0]);
            $corpus[] = ['name' => "b$i", 'tokens' => AstSerializer::tokens($blocks[0]->canonical)];
        }
        $this->assertGreaterThan(180, count($corpus), 'property corpus must stay substantial');

        // Injectivity: group by joined stream; every group must hold one
        // and the same token list.
        /** @var array<string,list<string>> $byStream */
        $byStream = [];
        /** @var array<string,list<string>> $byGram */
        $byGram = [];
        foreach ($corpus as $entry) {
            $joined = implode(Delimiters::TOKEN_JOIN, $entry['tokens']);
            $digest = hash('xxh128', $joined);
            if (isset($byStream[$digest])) {
                $this->assertSame(
                    $byStream[$digest],
                    $entry['tokens'],
                    "distinct token lists joined to one TOKEN_JOIN stream (corpus member {$entry['name']})"
                );
            } else {
                $byStream[$digest] = $entry['tokens'];
            }

            $gramJoined = implode(Delimiters::NGRAM_SEP, $entry['tokens']);
            $gramDigest = hash('xxh128', $gramJoined);
            if (isset($byGram[$gramDigest])) {
                $this->assertSame(
                    $byGram[$gramDigest],
                    $entry['tokens'],
                    "distinct token lists joined to one NGRAM_SEP stream (corpus member {$entry['name']})"
                );
            } else {
                $byGram[$gramDigest] = $entry['tokens'];
            }

            // Unique decodability at both join layers.
            $this->assertSame(
                $entry['tokens'],
                self::decodeStream($joined, Delimiters::TOKEN_JOIN),
                "TOKEN_JOIN stream failed to decode back to its token list ({$entry['name']})"
            );
            $this->assertSame(
                $entry['tokens'],
                self::decodeStream($gramJoined, Delimiters::NGRAM_SEP),
                "NGRAM_SEP stream failed to decode back to its token list ({$entry['name']})"
            );
        }
    }

    /**
     * Replicates AntiUnifier::stmtHash (src/Refactor/AntiUnifier.php:523)
     * exactly — same token list, same HASH_JOIN joiner — so the NUL-joiner
     * collision is pinned at the statement-alignment channel itself while
     * the refactor stage stays byte-untouched in this fix round (its own
     * suite can't reach a private method; the probe keeps venue inside the
     * locked serializer test, disclosed in the fix report).
     */
    private function stmtHashOf(string $code): string
    {
        $stmts = self::$parser->parseCode($code);
        self::assertNotNull($stmts, 'fixture code must parse');
        return sha1(implode(Delimiters::HASH_JOIN, AstSerializer::tokens($stmts[0])));
    }

    public function testNullJoinerCollisionPairNowHashesDifferently(): void
    {
        // engine-S34a fix r1 F-1: the PD-07 construction with the joiner
        // swapped from \x1F to HASH_JOIN's "\0". At a674d90 the NUL bytes
        // inside the forged literal were unframed (NUL was not a danger
        // byte), so the two statements spliced to one \0-joined stream and
        // shared stmtHash 2cd8294e075e91882556a2f878d25672af284daa. With
        // "\x00" in FRAME_DANGER the value rides a length frame and the
        // joiner becomes injective at this layer too.
        $real = '<?php function z() { e("a", "b"); }';
        $forge = '<?php function z() { e("a\x00)\x00Arg#false#false(\x00Scalar_String_|s:b"); }';

        $this->assertNotSame($this->stmtHashOf($real), $this->stmtHashOf($forge));

        // Post-fix published identities (re-captured on the fix tip; old
        // value was the single collision digest quoted above on BOTH
        // sides). The clean side keeps its pre-framing hash: a NUL-free
        // value's framing decision does not change.
        $this->assertSame('2cd8294e075e91882556a2f878d25672af284daa', $this->stmtHashOf($real));

        // The forged literal is now visibly framed — the NUL bytes survive
        // inside `MARK len ':' raw`, never as token structure — and the
        // \0-joined stream decodes back to exactly its token list.
        $value = "a\x00)\x00Arg#false#false(\x00Scalar_String_|s:b";
        $forgeStmts = self::$parser->parseCode($forge);
        self::assertNotNull($forgeStmts);
        $forgeTokens = AstSerializer::tokens($forgeStmts[0]);
        $this->assertContains(
            'Scalar_String_|s:' . AstSerializer::FRAME_MARK . strlen($value) . ':' . $value,
            $forgeTokens,
            'a NUL-bearing literal must ride a length frame, not bare bytes'
        );
        $joined = implode(Delimiters::HASH_JOIN, $forgeTokens);
        $this->assertSame($forgeTokens, self::decodeStream($joined, Delimiters::HASH_JOIN));
    }
}

