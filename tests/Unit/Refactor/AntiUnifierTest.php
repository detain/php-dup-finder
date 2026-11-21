<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\ParameterSynthesizer;
use Phpdup\Refactor\PatternRecognizer;
use Phpdup\Refactor\SignatureBuilder;

final class AntiUnifierTest extends TestCase
{
    public function testThresholdAndRoleHolesAreRecovered(): void
    {
        $cluster = $this->clusterFor([
            'function notifyHigh($u, $s) { if ($s > 10) { send("admin", $u); } }',
            'function notifyMid($u, $s) { if ($s > 20) { send("moderator", $u); } }',
        ]);

        (new AntiUnifier())->unify($cluster);
        (new ParameterSynthesizer())->synthesize($cluster);
        (new SignatureBuilder())->buildSignature($cluster);

        $this->assertCount(2, $cluster->holes);

        $intHole = null;
        $stringHole = null;
        foreach ($cluster->holes as $h) {
            $values = array_values(array_unique($h->observedValues));
            sort($values);
            if ($values === ['10', '20']) {
                $intHole = $h;
            }
            if ($values === ["'admin'", "'moderator'"]) {
                $stringHole = $h;
            }
        }
        $this->assertNotNull($intHole, 'expected a hole with observed values 10 and 20');
        $this->assertNotNull($stringHole, 'expected a hole with observed values "admin" and "moderator"');
        $this->assertSame('int',    $intHole->inferredType);
        $this->assertSame('string', $stringHole->inferredType);

        $this->assertNotNull($cluster->signature);
        $this->assertStringContainsString('notifyBy', (string)$cluster->signature);
    }

    public function testThreeMembersAccumulateObservedValues(): void
    {
        $cluster = $this->clusterFor([
            'function notifyHigh($u, $s) { if ($s > 10) { send("admin", $u); } }',
            'function notifyMid($u, $s) { if ($s > 20) { send("moderator", $u); } }',
            'function notifyLow($u, $s) { if ($s > 30) { send("editor", $u); } }',
        ]);

        (new AntiUnifier())->unify($cluster);

        foreach ($cluster->holes as $h) {
            // every hole must have one observed value per member
            $this->assertCount(3, $h->observedValues, "hole {$h->placeholder} ({$h->kind}) has " . count($h->observedValues) . ' observations');
        }
    }

    public function testStrategyPatternIsTagged(): void
    {
        $cluster = $this->clusterFor([
            'function f($x) { return $this->validate($x); }',
            'function f($x) { return $this->compile($x); }',
        ]);
        (new AntiUnifier())->unify($cluster);
        (new ParameterSynthesizer())->synthesize($cluster);
        (new PatternRecognizer())->tag($cluster);

        // exactly one hole — the method name — so strategy tag should fire
        $this->assertContains('strategy', $cluster->patternTags);
    }

    public function testStmtArraysOfDifferentLengthsProduceOptionalBlockHoles(): void
    {
        // Same skeleton, but the second function is missing the last two
        // statements. With type-3 detection on, AntiUnifier should emit
        // optional_block holes for the trailing pair.
        $cluster = $this->clusterFor([
            'function long()  { a(); b(); c(); d(); e(); }',
            'function short() { a(); b(); c(); }',
        ]);

        (new AntiUnifier(optionalBlocksEnabled: true))->unify($cluster);

        $optionals = array_values(array_filter(
            $cluster->holes,
            static fn($h) => $h->kind === 'optional_block',
        ));
        $this->assertCount(2, $optionals, 'two trailing statements should each become optional_block holes');

        // Each optional hole should have exactly one <absent> per member that
        // lacked the segment, plus the seed's repr for the member that had it.
        foreach ($optionals as $hole) {
            $absent = array_filter($hole->observedValues, static fn($v) => $v === '<absent>');
            $this->assertCount(1, $absent, "{$hole->placeholder}: exactly one member should be <absent>");
            $this->assertCount(2, $hole->observedValues, 'one observed value per cluster member');
        }
    }

    public function testOptionalBlocksDisabledFallsBackToWholeArrayHole(): void
    {
        // Same fixture as the previous test, but with the feature off.
        // The legacy code path should kick in: differing array lengths →
        // a single subtree hole rather than per-statement optional holes.
        $cluster = $this->clusterFor([
            'function long()  { a(); b(); c(); d(); e(); }',
            'function short() { a(); b(); c(); }',
        ]);

        (new AntiUnifier(optionalBlocksEnabled: false))->unify($cluster);

        foreach ($cluster->holes as $h) {
            $this->assertNotSame('optional_block', $h->kind, 'no optional_block holes when feature is disabled');
        }
        $this->assertNotEmpty($cluster->holes, 'legacy whole-array subtree hole should still form');
    }

    public function testOptionalBlocksMaxPerClusterCapsSegments(): void
    {
        // Five trailing-statement gaps would exceed the cap of 2; the unifier
        // should fall back to a whole-array hole instead.
        $cluster = $this->clusterFor([
            'function long()  { a(); b(); c(); d(); e(); f(); g(); }',
            'function short() { a(); b(); }',
        ]);

        (new AntiUnifier(optionalBlocksEnabled: true, optionalBlocksMaxPerCluster: 2))->unify($cluster);

        $optionals = array_filter(
            $cluster->holes,
            static fn($h) => $h->kind === 'optional_block',
        );
        $this->assertCount(0, $optionals, 'cap exceeded — should fall back to a whole-array hole');
    }

    public function testSeedIsTheLargestMember(): void
    {
        // Place the longer block second; AntiUnifier must still pick it as
        // the seed so the optional_block holes form for the missing tail
        // (instead of the alignment failing because the seed is the shorter
        // member). After unification, observed values are remapped to the
        // original cluster.members order, so member[0] is the short one.
        $cluster = $this->clusterFor([
            'function short() { a(); b(); c(); }',
            'function long()  { a(); b(); c(); d(); e(); }',
        ]);

        (new AntiUnifier(optionalBlocksEnabled: true))->unify($cluster);

        $optionals = array_values(array_filter(
            $cluster->holes,
            static fn($h) => $h->kind === 'optional_block',
        ));
        $this->assertCount(2, $optionals);

        // Member 0 (the short function) was absent; member 1 (the long one)
        // had each segment.
        foreach ($optionals as $hole) {
            $this->assertSame('<absent>', $hole->observedValues[0], 'short member is index 0; should be <absent>');
            $this->assertNotSame('<absent>', $hole->observedValues[1], 'long member is index 1; should have the stmt');
        }
    }

    /** @param list<string> $functions */
    private function clusterFor(array $functions): Cluster
    {
        $parser = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = [];
        foreach ($functions as $i => $code) {
            $stmts = $parser->parseCode('<?php ' . $code);
            $extracted = $extractor->extract("test{$i}.php", $stmts);
            $this->assertNotEmpty($extracted);
            $blocks[] = $extracted[0];
        }
        return new Cluster('TEST', $blocks, 1.0, false);
    }

    public function testGetLastHolePathsReturnsHoleIdToPathMap(): void
    {
        $cluster = $this->clusterFor([
            'function notifyHigh($u, $s) { if ($s > 10) { send("admin", $u); } }',
            'function notifyMid($u, $s) { if ($s > 20) { send("moderator", $u); } }',
        ]);

        $antiUnifier = new AntiUnifier();
        $antiUnifier->unify($cluster);

        $paths = $antiUnifier->getLastHolePaths();
        $this->assertNotEmpty($paths, 'hole paths should not be empty after unify');

        foreach ($paths as $holeId => $path) {
            $this->assertIsString($holeId);
            $this->assertIsArray($path);
            $this->assertNotEmpty($path);
        }

        $this->assertNotEmpty($cluster->holePaths, 'cluster.holePaths should be populated');
        $this->assertSame($paths, $cluster->holePaths);
    }

    // ── item 5.4(b): structural gaps are Hole::MISSING + degraded, not text ──

    public function testWholeArrayFallbackHoleRecordsGapsNotMissingStrings(): void
    {
        // Differing statement-count with optional-block detection OFF: the
        // legacy fallback makes one subtree hole over the whole `stmts`
        // array, passing null for BOTH template and member nodes. Pre-5.4
        // that emitted the string '<missing>' as each member's "observed
        // value"; now the values are structural gaps and the hole says so.
        $cluster = $this->clusterFor([
            'function long()  { a(); b(); c(); d(); e(); }',
            'function long()  { a(); b(); c(); }',
        ]);

        (new AntiUnifier(optionalBlocksEnabled: false))->unify($cluster);

        $this->assertNotEmpty($cluster->holes);
        foreach ($cluster->holes as $h) {
            $this->assertNotContains(
                '<missing>',
                $h->observedValues,
                "hole {$h->placeholder} leaked the legacy impostor string",
            );
            $this->assertTrue($h->degraded, "hole {$h->placeholder} should be degraded");
            $this->assertSame(['', ''], $h->observedValues);
        }
    }

    public function testXorNullSubnodeHoleIsDegradedGapEvidence(): void
    {
        // Same statement class on both sides, but one member's `expr`
        // subnode is null (bare return) → the xor-null path creates a hole
        // without ever observing the template node.
        $cluster = $this->clusterFor([
            'function f($x) { return $x + 1; }',
            'function f($x) { return; }',
        ]);

        (new AntiUnifier())->unify($cluster);

        $gapped = array_values(array_filter($cluster->holes, static fn($h) => $h->degraded));
        $this->assertNotEmpty($gapped, 'the missing expr slot must produce degraded evidence');
        foreach ($gapped as $h) {
            $this->assertNotContains('<missing>', $h->observedValues);
        }
    }

    public function testCompleteAgreementHolesAreNotDegraded(): void
    {
        // The plain literal-hole fixture: every member was truly observed,
        // so degraded stays false and no gap markers appear anywhere.
        $cluster = $this->clusterFor([
            'function notifyHigh($u, $s) { if ($s > 10) { send("admin", $u); } }',
            'function notifyMid($u, $s) { if ($s > 20) { send("moderator", $u); } }',
        ]);

        (new AntiUnifier())->unify($cluster);

        $this->assertNotEmpty($cluster->holes);
        foreach ($cluster->holes as $h) {
            $this->assertFalse($h->degraded);
            $this->assertNotContains('', $h->observedValues);
        }
    }

    // ── item 5.4(d): mined function names for unnamed template bodies ────────

    public function testMinedNameFromHoleFreeBodyStatements(): void
    {
        // Two anonymous `if` blocks: the ONLY member-name pool is empty, so
        // pre-5.4 the signature was forced to extractedFunction. The call
        // graph outside the holes (the threshold literal differs) says
        // "send" — and with a lone property read, its subject "limit".
        $cluster = $this->ifClusterFor([
            'if ($s > 1) { $m->notify($s, $m->limit); }',
            'if ($s > 2) { $m->notify($s, $m->limit); }',
        ]);

        (new AntiUnifier())->unify($cluster);
        (new ParameterSynthesizer())->synthesize($cluster);
        (new SignatureBuilder())->buildSignature($cluster);

        $this->assertStringContainsString('function notifyLimit(', (string)$cluster->signature);
    }

    public function testMinedNameStaysSilentOnAmbiguousCallees(): void
    {
        // Two callees with equal weight → no strict winner → honest fallback,
        // never a coin flip.
        $cluster = $this->ifClusterFor([
            'if ($s > 1) { send($s); page($s); }',
            'if ($s > 2) { send($s); page($s); }',
        ]);

        (new AntiUnifier())->unify($cluster);
        (new ParameterSynthesizer())->synthesize($cluster);
        (new SignatureBuilder())->buildSignature($cluster);

        $this->assertStringContainsString('extractedFunction', (string)$cluster->signature);
    }

    public function testCalleeNameInsideAHoleNeverLeaksIntoTheMining(): void
    {
        // The only call site differs between members (validate vs compile),
        // so its Identifier is a hole — the mined pool must come up EMPTY
        // (using the seed's callee would bake one member's data into the
        // shared name) and the signature falls back.
        $cluster = $this->ifClusterFor([
            'if ($x) { $m->validate($x); }',
            'if ($x) { $m->compile($x); }',
        ]);

        (new AntiUnifier())->unify($cluster);
        (new ParameterSynthesizer())->synthesize($cluster);
        (new SignatureBuilder())->buildSignature($cluster);

        $this->assertStringContainsString('extractedFunction', (string)$cluster->signature);
        $this->assertStringNotContainsString('validate', (string)$cluster->signature);
        $this->assertStringNotContainsString('compile', (string)$cluster->signature);
    }

    public function testNamedMembersNeverTakeTheMiningPath(): void
    {
        // Regression lock for the byte-identity claim: clusters WITH member
        // names keep the historical longest-prefix suggestion untouched.
        $cluster = $this->clusterFor([
            'function notifyHigh($u, $s) { if ($s > 10) { send("admin", $u); logAudit($u); } }',
            'function notifyMid($u, $s) { if ($s > 20) { send("moderator", $u); logAudit($u); } }',
        ]);

        (new AntiUnifier())->unify($cluster);
        (new ParameterSynthesizer())->synthesize($cluster);
        (new SignatureBuilder())->buildSignature($cluster);

        $this->assertStringContainsString('notifyBy', (string)$cluster->signature);
    }

    /** @param list<string> $codes each an `if` statement body source */
    private function ifClusterFor(array $codes): Cluster
    {
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks    = [];
        foreach ($codes as $i => $code) {
            $stmts     = $parser->parseCode('<?php ' . $code);
            $extracted = $extractor->extract("if{$i}.php", $stmts);
            $this->assertNotEmpty($extracted, "expected an if-kind block for: {$code}");
            $block = $extracted[0];
            $this->assertSame('', (string)$block->name, 'precondition: if-blocks carry no name to suggest from');
            $blocks[] = $block;
        }
        return new Cluster('IFTEST', $blocks, 1.0, false);
    }

    // ── output-S25 (php-dup-finder-output-014): seed trials carry forward ────

    public function testTrialPathNeverDeepClonesTheSeedTemplate(): void
    {
        $file   = (new \ReflectionClass(AntiUnifier::class))->getFileName();
        $this->assertIsString($file);
        $source = (string)file_get_contents($file);

        // Structure check (grep-as-spy on the compiled unit): every walk of
        // a >=3-member cluster runs through the seed-search methods, so the
        // trial path is clone-free exactly when these bodies are clone-free.
        $bodyOf = static function (string $method) use ($source): string {
            $ref   = new \ReflectionMethod(AntiUnifier::class, $method);
            $lines = array_slice(
                explode("\n", $source),
                (int)$ref->getStartLine() - 1,
                (int)$ref->getEndLine() - (int)$ref->getStartLine() + 1,
            );
            return implode("\n", $lines);
        };

        foreach (['pickBestSeed', 'rankSeedCandidates', 'trialUnify'] as $method) {
            $this->assertStringNotContainsString(
                'deepClone',
                $bodyOf($method),
                "$method must not clone the template — walk() only reads it",
            );
        }

        // The second half of the finding: unify() must not re-walk after the
        // search — all walking happens inside the carried candidate trials.
        $this->assertStringNotContainsString('$this->walk(', $bodyOf('unify'));

        // The ONLY Normalizer::deepClone call sites left are the two
        // legitimate ones in unify(): the single-member template and the
        // mutation-ready generalizedAst.
        $this->assertSame(2, substr_count($source, 'Normalizer::deepClone('));
        $this->assertSame(2, substr_count($bodyOf('unify'), 'Normalizer::deepClone('));
    }

    public function testGeneralizedAstRemainsAFreshCloneOfTheCarriedSeed(): void
    {
        // Winner carries forward, but the template the consumers receive is
        // still a private deep copy — ApplyExtractor may mutate it without
        // touching the member's own AST.
        $cluster = $this->clusterFor([
            'function long()  { a(); b(); c(); d(); e(); }',
            'function mid()   { a(); b(); c(); d(); }',
            'function short() { a(); b(); c(); }',
        ]);

        $antiUnifier = new AntiUnifier();
        $antiUnifier->unify($cluster);

        $this->assertSame(3, $antiUnifier->getLastWalkCount(), 'three candidate walks, zero discarded re-walks');
        $this->assertSame([], $cluster->holes, 'the zero-hole seed wins exactly as before the refactor');

        $seed = $cluster->members[2];
        $this->assertNotNull($seed->ast);
        $this->assertNotNull($cluster->generalizedAst);
        $this->assertNotSame($seed->ast, $cluster->generalizedAst);

        $printer = new Standard();
        $this->assertSame(
            trim($printer->prettyPrint([$seed->ast])),
            trim($printer->prettyPrint([$cluster->generalizedAst])),
        );
    }

    /**
     * @param list<string> $codes
     * @param array<string, mixed> $opts
     * @param array{seed_matches: list<int>, generalizedAst: string, holes: list<array{0: string, 1: string, 2: list<string>, 3: bool}>, holePaths: array<string, list<int|string>>} $expect
     *
     * @dataProvider preChangeSeedPins
     */
    public function testSeedAndHoleSetAreIdenticalToPreChange(
        array $codes,
        array $opts,
        array $expect,
        int $walks,
    ): void {
        $cluster = $this->clusterFor($codes);

        $antiUnifier = new AntiUnifier(...$opts);
        $antiUnifier->unify($cluster);

        $printer = new Standard();
        $ast     = $cluster->generalizedAst;
        $this->assertNotNull($ast);
        $printed = trim($printer->prettyPrint([$ast]));
        $this->assertSame($expect['generalizedAst'], $printed);

        $matches = [];
        foreach ($cluster->members as $i => $member) {
            if ($member->ast !== null && trim($printer->prettyPrint([$member->ast])) === $printed) {
                $matches[] = $i;
            }
        }
        $this->assertSame($expect['seed_matches'], $matches, 'chosen seed must be the pre-change pick');

        $holes = [];
        foreach ($cluster->holes as $h) {
            $holes[] = [$h->placeholder, $h->kind, $h->observedValues, $h->degraded];
        }
        $this->assertSame($expect['holes'], $holes, 'hole set must be identical to pre-change');

        // ApplyExtractor wire shape: holeId => path, unchanged.
        $this->assertSame($expect['holePaths'], $cluster->holePaths);
        $this->assertSame($expect['holePaths'], $antiUnifier->getLastHolePaths());

        $this->assertSame(
            $walks,
            $antiUnifier->getLastWalkCount(),
            'one walk per candidate with the winner carried — no discarded re-walk',
        );
    }

    /**
     * Measured on master BEFORE the carry-forward refactor (output-014
     * rebase): seed pick + hole set as they were BEFORE the carry-forward
     * refactor — pinned so the refactor is proven identity-preserving.
     *
     * @return array<string, array{codes: list<string>, opts: array<string, mixed>, expect: array{seed_matches: list<int>, generalizedAst: string, holes: list<array{0: string, 1: string, 2: list<string>, 3: bool}>, holePaths: array<string, list<int|string>>}, walks: int}>
     */
    public static function preChangeSeedPins(): array
    {
        return [
            'three_notify' => [
                'codes' => [
                    'function notifyHigh($u, $s) { if ($s > 10) { send("admin", $u); } }',
                    'function notifyMid($u, $s) { if ($s > 20) { send("moderator", $u); } }',
                    'function notifyLow($u, $s) { if ($s > 30) { send("editor", $u); } }',
                ],
                'opts' => [],
                'expect' => [
                    'seed_matches' => [
                        0,
                    ],
                    'generalizedAst' => 'function notifyHigh($u, $s)
{
    if ($s > 10) {
        send("admin", $u);
    }
}',
                    'holes' => [
                        [
                            '__P0',
                            'literal',
                            [
                                '10',
                                '20',
                                '30',
                            ],
                            false,
                        ],
                        [
                            '__P1',
                            'literal',
                            [
                                '\'admin\'',
                                '\'moderator\'',
                                '\'editor\'',
                            ],
                            false,
                        ],
                    ],
                    'holePaths' => [
                        '__P0' => [
                            'stmts',
                            0,
                            'cond',
                            'right',
                        ],
                        '__P1' => [
                            'stmts',
                            0,
                            'stmts',
                            0,
                            'expr',
                            'args',
                            0,
                            'value',
                        ],
                    ],
                ],
                'walks' => 3,
            ],
            'three_selection' => [
                'codes' => [
                    'function a($x) { logit($x); return strlen($x); }',
                    'function b($x) { return strlen($x); }',
                    'function c($x) { return strlen($x) + 7; }',
                ],
                'opts' => [],
                'expect' => [
                    'seed_matches' => [
                        1,
                    ],
                    'generalizedAst' => 'function b($x)
{
    return strlen($x);
}',
                    'holes' => [
                        [
                            '__P0',
                            'subtree',
                            [
                                'strlen($x)',
                                'strlen($x)',
                                'strlen($x) + 7',
                            ],
                            false,
                        ],
                    ],
                    'holePaths' => [
                        '__P0' => [
                            'stmts',
                            0,
                            'expr',
                        ],
                    ],
                ],
                'walks' => 3,
            ],
            'three_optional_lcs' => [
                'codes' => [
                    'function long()  { a(); b(); c(); d(); e(); }',
                    'function mid()   { a(); b(); c(); d(); }',
                    'function short() { a(); b(); c(); }',
                ],
                'opts' => [
                    'optionalBlocksEnabled' => true,
                ],
                'expect' => [
                    'seed_matches' => [
                        2,
                    ],
                    'generalizedAst' => 'function short()
{
    a();
    b();
    c();
}',
                    'holes' => [],
                    'holePaths' => [],
                ],
                'walks' => 3,
            ],
            'two_member_pair' => [
                'codes' => [
                    'function long()  { a(); b(); c(); d(); e(); }',
                    'function short() { a(); b(); c(); }',
                ],
                'opts' => [],
                'expect' => [
                    'seed_matches' => [
                        0,
                    ],
                    'generalizedAst' => 'function long()
{
    a();
    b();
    c();
    d();
    e();
}',
                    'holes' => [
                        [
                            '__O0',
                            'optional_block',
                            [
                                'd();',
                                '<absent>',
                            ],
                            false,
                        ],
                        [
                            '__O1',
                            'optional_block',
                            [
                                'e();',
                                '<absent>',
                            ],
                            false,
                        ],
                    ],
                    'holePaths' => [
                        '__O0' => [
                            'stmts',
                            3,
                        ],
                        '__O1' => [
                            'stmts',
                            4,
                        ],
                    ],
                ],
                'walks' => 1,
            ],
            'three_xor_null' => [
                'codes' => [
                    'function f($x) { return $x + 1; }',
                    'function g($x) { return; }',
                    'function h($x) { return $x + 2; }',
                ],
                'opts' => [],
                'expect' => [
                    'seed_matches' => [
                        0,
                    ],
                    'generalizedAst' => 'function f($x)
{
    return $x + 1;
}',
                    'holes' => [
                        [
                            '__P0',
                            'subtree',
                            [
                                '',
                                '',
                                '$x + 2',
                            ],
                            true,
                        ],
                    ],
                    'holePaths' => [
                        '__P0' => [
                            'stmts',
                            0,
                            'expr',
                        ],
                    ],
                ],
                'walks' => 3,
            ],
            'three_optional_off' => [
                'codes' => [
                    'function long()  { a(); b(); c(); d(); e(); }',
                    'function mid()   { a(); b(); c(); d(); }',
                    'function short() { a(); b(); c(); }',
                ],
                'opts' => [
                    'optionalBlocksEnabled' => false,
                ],
                'expect' => [
                    'seed_matches' => [
                        0,
                    ],
                    'generalizedAst' => 'function long()
{
    a();
    b();
    c();
    d();
    e();
}',
                    'holes' => [
                        [
                            '__P0',
                            'subtree',
                            [
                                '',
                                '',
                                '',
                            ],
                            true,
                        ],
                    ],
                    'holePaths' => [
                        '__P0' => [
                            'stmts',
                        ],
                    ],
                ],
                'walks' => 3,
            ],
            'four_cap_two' => [
                'codes' => [
                    'function long()  { a(); b(); c(); d(); e(); f(); g(); }',
                    'function m1()    { a(); b(); c(); }',
                    'function m2()    { a(); b(); d(); e(); }',
                    'function short() { a(); b(); c(); d(); }',
                ],
                'opts' => [
                    'optionalBlocksEnabled' => true,
                    'optionalBlocksMaxPerCluster' => 2,
                ],
                'expect' => [
                    'seed_matches' => [
                        0,
                    ],
                    'generalizedAst' => 'function long()
{
    a();
    b();
    c();
    d();
    e();
    f();
    g();
}',
                    'holes' => [
                        [
                            '__P0',
                            'subtree',
                            [
                                '',
                                '',
                                '',
                                '',
                            ],
                            true,
                        ],
                    ],
                    'holePaths' => [
                        '__P0' => [
                            'stmts',
                        ],
                    ],
                ],
                'walks' => 3,
            ],
            'three_equal_ties' => [
                'codes' => [
                    'function p($x) { q($x); r(1); }',
                    'function p($x) { q($x); r(2); }',
                    'function p($x) { q($x); r(3); }',
                ],
                'opts' => [],
                'expect' => [
                    'seed_matches' => [
                        0,
                    ],
                    'generalizedAst' => 'function p($x)
{
    q($x);
    r(1);
}',
                    'holes' => [
                        [
                            '__P0',
                            'literal',
                            [
                                '1',
                                '2',
                                '3',
                            ],
                            false,
                        ],
                    ],
                    'holePaths' => [
                        '__P0' => [
                            'stmts',
                            1,
                            'expr',
                            'args',
                            0,
                            'value',
                        ],
                    ],
                ],
                'walks' => 3,
            ],
        ];
    }

}

