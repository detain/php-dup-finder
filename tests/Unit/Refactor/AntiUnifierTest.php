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
use Phpdup\Refactor\Hole;
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

    // ---- output-S28: float repr via var_export (int/float typing honesty) ----

    public function testIntegralFloatLiteralHoleReprsFloatHonestly(): void
    {
        // The old (string) cast rendered these Float_ members as ['1','2']
        // — identical rows to int literals, so the synthesizer typed the
        // float hole 'int'. var_export keeps the decimal point.
        $cluster = $this->clusterFor([
            'function f($x) { return finish(1.0, $x); }',
            'function f($x) { return finish(2.0, $x); }',
        ]);

        (new AntiUnifier())->unify($cluster);
        (new ParameterSynthesizer())->synthesize($cluster);

        $rows = $this->floatRows($cluster);
        sort($rows, SORT_STRING);
        $this->assertSame(['1.0', '2.0'], $rows);

        $hole = $this->holeCarrying($cluster, '1.0');
        $this->assertSame('float', $hole->inferredType);
    }

    public function testFloatVsIntLiteralHoleCarriesBothSpellings(): void
    {
        // 1.0 vs 1 diverge at class level → 'subtree' hole; the rows must
        // show both spellings so union typing sees int AND float.
        $cluster = $this->clusterFor([
            'function f($x) { return $x * 1.0; }',
            'function f($x) { return $x * 1; }',
        ]);

        (new AntiUnifier())->unify($cluster);
        (new ParameterSynthesizer())->synthesize($cluster);

        $rows = $this->floatRows($cluster);
        sort($rows, SORT_STRING);
        $this->assertSame(['1', '1.0'], $rows);

        $hole = $this->holeCarrying($cluster, '1.0');
        $this->assertSame('subtree', $hole->kind);
        $this->assertSame('int|float', $hole->inferredType);
    }

    public function testLargeFloatReprIsExponentShapedAndTypesFloat(): void
    {
        $cluster = $this->clusterFor([
            'function f($x) { return finish(1.0e25, $x); }',
            'function f($x) { return finish(2.0e25, $x); }',
        ]);

        (new AntiUnifier())->unify($cluster);
        (new ParameterSynthesizer())->synthesize($cluster);

        $rows = $this->floatRows($cluster);
        sort($rows, SORT_STRING);
        $this->assertSame(['1.0E+25', '2.0E+25'], $rows);

        $hole = $this->holeCarrying($cluster, '1.0E+25');
        $this->assertSame('float', $hole->inferredType);
    }

    public function testFloatReprStableUnderPrecision14And17(): void
    {
        // The acceptance inis: (string)-cast repr drifted between the two
        // (0.1 → '0.1' vs '0.10000000000000001'); var_export rides
        // serialize_precision (-1, shortest round-trip) instead and must
        // produce byte-equal hole rows under both settings.
        $codes = [
            'function f($x) { return finish(0.1, $x); }',
            'function f($x) { return finish(1.0e25, $x); }',
        ];

        $rowsByPrecision = [];
        $old = (string)ini_get('precision');
        try {
            foreach (['14', '17'] as $p) {
                ini_set('precision', $p);
                $cluster = $this->clusterFor($codes);
                (new AntiUnifier())->unify($cluster);
                $rows = $this->floatRows($cluster);
                sort($rows, SORT_STRING);
                $rowsByPrecision[$p] = $rows;
            }
        } finally {
            ini_set('precision', $old);
        }

        $this->assertSame(['0.1', '1.0E+25'], $rowsByPrecision['14']);
        $this->assertSame($rowsByPrecision['14'], $rowsByPrecision['17']);
    }

    /** @return list<string> every observed row across all holes */
    private function floatRows(Cluster $cluster): array
    {
        $rows = [];
        foreach ($cluster->holes as $h) {
            foreach ($h->observedValues as $v) {
                if ($v !== Hole::MISSING) {
                    $rows[] = $v;
                }
            }
        }
        return $rows;
    }

    private function holeCarrying(Cluster $cluster, string $row): Hole
    {
        foreach ($cluster->holes as $h) {
            if (in_array($row, $h->observedValues, true)) {
                return $h;
            }
        }
        $this->fail("no hole carries observed row '{$row}'");
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

        // Each optional hole should have exactly one canonical absence row
        // per member that lacked the segment, plus the seed's repr for the
        // member that had it (output-S27: Hole::MISSING is the row).
        foreach ($optionals as $hole) {
            $absent = array_filter($hole->observedValues, static fn($v) => $v === Hole::MISSING);
            $this->assertCount(1, $absent, "{$hole->placeholder}: exactly one member should carry the canonical absence row");
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

        // Member 0 (the short function) carries the canonical absence row;
        // member 1 (the long one) had each segment.
        foreach ($optionals as $hole) {
            $this->assertSame(Hole::MISSING, $hole->observedValues[0], 'short member is index 0; should be the canonical absence row');
            $this->assertNotSame(Hole::MISSING, $hole->observedValues[1], 'long member is index 1; should have the stmt');
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

    // ── output-S26 (php-dup-finder-output-018): hole-row contract ────────────
    //    + output-S27 (019): one canonical absence row end-to-end ─────────────
    //
    // observedValues is ONE ROW PER CLUSTER MEMBER, in cluster order. A row
    // the walk could not honestly fill is the explicit absence row
    // (Hole::MISSING — ''); a never-gathered observation degrades the hole,
    // a verified optional-segment absence does not (1.7). It is never
    // silently back-filled with the seed's repr, and the retired printable
    // sentinel exists nowhere in the data — reporters project it. The
    // seed-remap swap runs unconditionally over those placeholder rows.

    public function testHoleBornAfterDivergenceKeepsOneRowPerMemberWithExplicitGaps(): void
    {
        // The seed's first statement becomes a value hole for members t/c
        // (a(1) vs a(3)), so a hole born later for the b-segment is never
        // reached by t/c — their rows must be explicit gaps, not the old
        // seed-repr back-record that claimed agreement where none was seen
        // (output-018 leg a1).
        $cluster = $this->clusterFor([
            'function s26_d($x) { a(3); b(1); }',
            'function s26_t($x) { a(1); b(1); }',
            'function s26_c($x) { a(2); b(1); }',
            'function s26_r($x) { return 7; }',
        ]);

        $antiUnifier = new AntiUnifier();
        $antiUnifier->unify($cluster);

        $holes = [];
        foreach ($cluster->holes as $h) {
            $holes[] = [$h->placeholder, $h->kind, $h->observedValues, $h->isDegraded()];
        }

        $this->assertSame([
            ['__P0', 'literal',        ['3', '1', '2', ''],      true],
            ['__O1', 'optional_block', ['a(3);', '', '', ''],    true],
            ['__O2', 'optional_block', ['b(1);', 'b(1);', 'b(1);', ''], true],
        ], $holes);

        // Density: every hole carries a row for every member.
        $count = count($cluster->members);
        foreach ($cluster->holes as $h) {
            $this->assertCount($count, $h->observedValues, $h->placeholder . ' must stay dense');
        }

        // ApplyExtractor wire shape unchanged: holeId => template-relative path.
        $this->assertSame([
            '__P0' => ['stmts', 0, 'expr', 'args', 0, 'value'],
            '__O1' => ['stmts', 0],
            '__O2' => ['stmts', 1],
        ], $antiUnifier->getLastHolePaths());
    }

    public function testMemberExtrasDegradeNewlyBornHolesWithoutTouchingRows(): void
    {
        // The middle member's `return 7;` is behavior the optional-segment
        // alignment silently drops — the hole born beside it degrades, but
        // its rows stay purely verified-absence evidence (no gap row is
        // invented for what the template simply never aligned to). output-
        // S27: the verified-absence row IS Hole::MISSING now; the dropped
        // statement rides the flag, not a fabricated extra row.
        $cluster = $this->clusterFor([
            'function s26_e0($x) { a(1); b(1); c(1); }',
            'function s26_e1($x) { return 7; }',
            'function s26_e2($x) { a(2); b(1); c(1); }',
        ]);

        $antiUnifier = new AntiUnifier();
        $antiUnifier->unify($cluster);

        $this->assertCount(1, $cluster->holes);
        $hole = $cluster->holes[0];
        $this->assertSame('__O0', $hole->placeholder);
        $this->assertSame('optional_block', $hole->kind);
        $this->assertSame([Hole::MISSING, 'return 7;', Hole::MISSING], $hole->observedValues);
        $this->assertTrue($hole->isDegraded(), 'silently dropped member statement must be observable as degradation');
        $missing = array_filter($hole->observedValues, static fn($v) => $v === Hole::MISSING);
        $this->assertCount(2, $missing, 'degrade-without-a-row: the two absence rows are complete evidence, the claim is not');
    }

    public function testAbsentNeverStampedIntoValueHoleAtCollisionPath(): void
    {
        // A subtree hole born at stmts/1 (return 5; vs m();); the short
        // last member has no statement there and its slot content is
        // unrecoverable — the row is an explicit gap (output-018 leg 4).
        // Since output-S27 the gap row and the optional-segment absence
        // row share the one canonical marker; what stays impossible is any
        // printable impostor reaching the data — hence the sentinel pin
        // below, kept as a permanent retirement regression. The short
        // member ranks out of the seed-candidate pool (top 3 by size), so
        // the collision genuinely lands on a non-optional hole.
        $cluster = $this->clusterFor([
            'function s26_h0($x) { k(); m(); }',
            'function s26_h1($x) { k(); return 5; }',
            'function s26_h2($x) { k(); q(); }',
            'function s26_h3($x) { k(); }',
        ]);

        $antiUnifier = new AntiUnifier();
        $antiUnifier->unify($cluster);

        $this->assertCount(1, $cluster->holes);
        $hole = $cluster->holes[0];
        $this->assertSame('__P0', $hole->placeholder);
        $this->assertSame('subtree', $hole->kind);
        $this->assertSame(['m();', 'return 5;', 'q();', ''], $hole->observedValues);
        $this->assertNotContains('<absent>', $hole->observedValues, "'<absent>' was retired to the reporters' render projection — the data layer must never mint it, here or anywhere (output-S27)");
        $this->assertTrue($hole->isDegraded());
        $this->assertSame(['__P0' => ['stmts', 1]], $antiUnifier->getLastHolePaths());
    }

    public function testValueHoleCarriesExplicitGapRowForNeverReachedMember(): void
    {
        // output-S26-r1 §MINOR-1, reached by the shape the CURRENT engine
        // actually mints (three probed variants of the review's literal
        // recipe — diverging arg under a short sibling member — all flip
        // the seed-winner to zero-hole posture; premise adjudicated on
        // disk, see reviews/exec/output-S27.impl.md). The MU5 blind spot
        // is back-recording the seed's repr where nothing was observed;
        // this pins the surviving reachable arm of the same loop: the
        // value hole born at stmts/0 for seed-vs-m1 divergence keeps an
        // EXPLICIT '' row — not a seed-repr claim — for m3, the member
        // whose single statement never reaches the divergence, and the
        // hole degrades. A back-record regression turns this row red.
        $cluster = $this->clusterFor([
            'function s27_p0($x) { a(3); b(1); }',
            'function s27_p1($x) { a(1); b(1); }',
            'function s27_p2($x) { a(2); b(1); }',
            'function s27_p3($x) { return 7; }',
        ]);

        $antiUnifier = new AntiUnifier();
        $antiUnifier->unify($cluster);

        $hole = null;
        foreach ($cluster->holes as $h) {
            if ($h->placeholder === '__P0' && $h->kind === 'literal') {
                $hole = $h;
            }
        }
        $this->assertNotNull($hole, 'the a-arg divergence must mint a literal hole');
        $this->assertSame(['3', '1', '2', ''], $hole->observedValues, 'never-reached member keeps an explicit gap row, not the seed repr');
        $this->assertTrue($hole->isDegraded(), 'a never-gathered row degrades the hole');
    }

    public function testOptionalHoleRecordsCanonicalAbsenceWithoutDegrading(): void
    {
        // Mirror of the collision pin, output-S27 side: when the hole IS an
        // optional segment, the member that lacks it carries the canonical
        // absence row (Hole::MISSING) recorded WITHOUT degrading — the walk
        // verifiably saw the slot and it holds nothing. The retired
        // printable sentinel now exists only inside reporters' projection.
        $cluster = $this->clusterFor([
            'function s26_i0($x) { k(); }',
            'function s26_i1($x) { k(); m(); }',
            'function s26_i2($x) { k(); return 5; }',
            'function s26_i3($x) { k(); q(); }',
        ]);

        $antiUnifier = new AntiUnifier();
        $antiUnifier->unify($cluster);

        $this->assertCount(1, $cluster->holes);
        $hole = $cluster->holes[0];
        $this->assertSame('__O0', $hole->placeholder);
        $this->assertSame('optional_block', $hole->kind);
        $this->assertSame([Hole::MISSING, 'm();', 'return 5;', 'q();'], $hole->observedValues);
        $this->assertFalse($hole->isDegraded(), 'every member was honestly observed at this path');
    }

    public function testSeedRemapSwapRunsUnconditionallyWithPlaceholderRows(): void
    {
        // Seed is the LAST member (index 4). The old isset() guard skipped
        // the remap whenever a short hole list left the seed's counterpart
        // missing, pairing every row beside the wrong member. With density
        // guaranteed, the swap must run and land the seed's rows at index
        // 4 — and pass-aware backfill keeps m1's clean visit faithful
        // ('k(1);'), while the never-honestly-observed members carry the
        // canonical absence row (Hole::MISSING since output-S27).
        $codes = [
            'function s26_m0($x) { k(7); }',
            'function s26_m1($x) { k(1); }',
            'function s26_m2($x) { k(2); }',
            'function s26_m3($x) { y(3); }',
            'function s26_m4($x) { k(1); g(); }',
        ];
        $cluster = $this->clusterFor($codes);

        $antiUnifier = new AntiUnifier();
        $antiUnifier->unify($cluster);

        // Carry-forward invariant (output-014/S25): the winning seed IS the
        // generalized template — here the last member.
        $printer = new Standard();
        $this->assertNotNull($cluster->generalizedAst);
        $this->assertNotNull($cluster->members[4]->ast);
        $this->assertSame(
            trim($printer->prettyPrint([$cluster->members[4]->ast])),
            trim($printer->prettyPrint([$cluster->generalizedAst])),
        );

        $holes = [];
        foreach ($cluster->holes as $h) {
            $holes[] = [$h->placeholder, $h->kind, $h->observedValues, $h->isDegraded()];
        }
        $this->assertSame([
            ['__O0', 'optional_block', ['', '', '', '', 'g();'],        false],
            ['__O1', 'optional_block', ['', 'k(1);', '', '', 'k(1);'],  true],
        ], $holes);

        // Rows are positionally dense: index i always describes member i —
        // the seed row sits at index 4 exactly where the swap put it.
        $count = count($cluster->members);
        foreach ($cluster->holes as $h) {
            $this->assertCount($count, $h->observedValues, $h->placeholder . ' must stay dense');
        }
    }

    public function testFaithfulDeepHolesKeepOwnValuesAndStayNonDegraded(): void
    {
        // A member that diverges in a SIBLING hole still records its own
        // value in every hole it genuinely visits — the passed-model must
        // not smear gaps or degradation over faithfully observed rows.
        $cluster = $this->clusterFor([
            'function s26_b0($x) { k(7); }',
            'function s26_b1($x) { k(1); }',
            'function s26_b2($x) { y(3); }',
            'function s26_b3($x) { k(2); }',
        ]);

        $antiUnifier = new AntiUnifier();
        $antiUnifier->unify($cluster);

        $holes = [];
        foreach ($cluster->holes as $h) {
            $holes[] = [$h->placeholder, $h->kind, $h->observedValues, $h->isDegraded()];
        }
        $this->assertSame([
            ['__P0', 'literal', ['7', '1', '3', '2'], false],
            ['__P1', 'name',    ['k', 'k', 'y', 'k'], false],
        ], $holes, 'member b2 (y(3)) observes its own value in both holes — no gap, no degradation');
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
                                '',
                            ],
                            false,
                        ],
                        [
                            '__O1',
                            'optional_block',
                            [
                                'e();',
                                '',
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

