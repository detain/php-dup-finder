<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Architecture\Analyzers;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;
use Phpdup\Architecture\Analyzers\DriftAnalyzer;
use Phpdup\Architecture\Finding;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\Hole;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\LineRange;

/**
 * Item 4.1: the drift analyzer's four predicates, each pinned with a
 * fire case on a synthetic tests/Fixtures/drift/ cluster AND an explicit
 * negative control (uniform clusters under the FULL vocabulary may
 * produce zero findings — the B1 M-1 lesson: no vacuous pins).
 *
 * The clusters are unified through the real AntiUnifier, so these tests
 * also prove the ObservationDescriptor sidecar reaches the analyzer
 * through the walk (template descriptors at hole birth, witnessed
 * absences at the alignment sites) — the substrate from item 4.1's first
 * batch, consumed end to end.
 */
final class DriftAnalyzerTest extends TestCase
{
    /** Full vocabulary: every predicate on, sanitizer + auth lists. */
    private const VOCAB = [
        'predicates' => [
            DriftAnalyzer::PREDICATE_GUARD,
            DriftAnalyzer::PREDICATE_SANITIZER,
            DriftAnalyzer::PREDICATE_LITERAL,
            DriftAnalyzer::PREDICATE_ODD_ONE_OUT,
        ],
        'sanitizers' => ['real_escape', 'sanitize', 'mysql_real_escape_string', 'intval'],
        'auth'       => ['require_auth', 'reauth', 'check_admin', 'is_admin'],
        // r1 m-2: the option arm is SCOPE-gated by this list — the fire
        // pins need the enumerated security-relevant constant present.
        'options'    => ['CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST'],
    ];

    // ------------------------------------------------------------- guard-drift

    public function testGuardDriftNamesTheDeficientMember(): void
    {
        $findings = $this->analyzeFixture('guard');

        self::assertCount(1, $findings);
        self::assertSame('drift-missing-guard', $findings[0]->code);
        self::assertSame(Finding::SEVERITY_ERROR, $findings[0]->severity);
        self::assertStringContainsString('GuardMissing::handle', $findings[0]->message);
        self::assertStringContainsString('present in 3 of 4 members', $findings[0]->message);
        self::assertStringNotContainsString('GuardedOne::handle', $findings[0]->message,
            'a guarded member is never named deficient');
    }

    public function testGuardDriftFiresOnTwoMemberPair(): void
    {
        $findings = $this->analyzeFixture('guard_pair');

        self::assertCount(1, $findings);
        self::assertSame('drift-missing-guard', $findings[0]->code);
        self::assertStringContainsString('PairRaw::handle', $findings[0]->message);
        self::assertStringContainsString('present in 1 of 2 members', $findings[0]->message);
    }

    public function testGuardDriftSurvivesReplacementStatementTrio(): void
    {
        // TrioReplaced swapped its guard for a smaller statement: the
        // alignment surfaces the swap as a subtree hole and the odd-one-out
        // predicate names it — the guard-drift predicate does not, because
        // the row is a divergent value, not a witnessed absence.
        $findings = $this->analyzeFixture('guard_trio');

        $codes = array_map(static fn(Finding $f): string => $f->code, $findings);
        self::assertContains('drift-odd-one-out', $codes);
        foreach ($findings as $f) {
            if ($f->code !== 'drift-odd-one-out') {
                continue;
            }
            self::assertStringContainsString('TrioReplaced::handle', $f->message);
        }
    }

    public function testPureDeltaTrioIsSuppressedByTheSeedLaw(): void
    {
        // KNOWN STRUCTURAL LIMIT (docblocked on the analyzer): a three
        // member cluster whose only delta is the dropped guard collapses —
        // the fewest-holes seed law (output-014) picks the guard-less
        // template and no optional hole is ever born. Pinned honestly so a
        // future seed-law change trips this test, not the reader.
        $guarded  = 'public function handle($id) { if (!is_numeric($id)) { fail(101); return; } work($id); }';
        $guardless = 'public function handle($id) { work($id); }';
        $cluster  = $this->inlineCluster([$guarded, $guarded, $guardless]);

        (new AntiUnifier(null, true, 3))->unify($cluster);
        self::assertSame([], $cluster->holes, 'the seed law prefers the guard-less template');

        $findings = (new DriftAnalyzer(self::VOCAB))->analyze($cluster);
        self::assertSame([], $findings);
    }

    public function testGuardDriftControlOnUniformCluster(): void
    {
        self::assertSame([], $this->analyzeFixture('guard_uniform'),
            'NEGATIVE CONTROL: identical guards produce no presence holes');
    }

    // ---------------------------------------------------- cap-3 (guard drift)

    public function testOptionalCapCollapsesTheFourthGuardPosition(): void
    {
        // Default cap: the third alignment births the last hole the cap
        // allows; the fourth distinct drop blows the whole stmts array to a
        // subtree hole — FiveDropFour's rows at g1..g3 are never-gathered
        // pads (MISSING without witness) and the analyzer must not name it.
        $findings = $this->analyzeFixture('guard_five');

        self::assertCount(3, $findings);
        foreach ($findings as $f) {
            self::assertSame('drift-missing-guard', $f->code);
            self::assertStringContainsString('unobserved', $f->message);
        }
        $joined = implode("\n", array_map(static fn(Finding $f): string => $f->message, $findings));
        self::assertStringContainsString('FiveDropOne::handle', $joined);
        self::assertStringContainsString('FiveDropTwo::handle', $joined);
        self::assertStringContainsString('FiveDropThree::handle', $joined);
        self::assertStringNotContainsString('FiveDropFour::handle', $joined,
            'the collapsed position is ambiguous and must stay unnamed');
    }

    public function testRaisedCapPreservesTheFourthGuardPosition(): void
    {
        // The tuning proof: with the cap raised, the 4+-member divergence
        // survives whole — every dropper is named, none as ambiguous.
        $findings = $this->analyzeFixture('guard_five', 8);

        self::assertCount(4, $findings);
        $joined = implode("\n", array_map(static fn(Finding $f): string => $f->message, $findings));
        foreach (['FiveDropOne', 'FiveDropTwo', 'FiveDropThree', 'FiveDropFour'] as $named) {
            self::assertStringContainsString($named . '::handle', $joined);
        }
        foreach ($findings as $f) {
            self::assertSame('drift-missing-guard', $f->code);
            self::assertStringContainsString('present in 4 of 5 members', $f->message);
            self::assertStringNotContainsString('unobserved', $f->message);
        }
    }

    // --------------------------------------------------- auth (guard-drift predicate)

    public function testAuthDriftFiresThroughTheAuthVocabulary(): void
    {
        // The dropped statement is a plain call, not a guard: the
        // guard-drift predicate recognizes it through the auth vocabulary
        // and reports drift-missing-auth.
        $findings = $this->analyzeFixture('auth');

        self::assertCount(1, $findings);
        self::assertSame('drift-missing-auth', $findings[0]->code);
        self::assertSame(Finding::SEVERITY_ERROR, $findings[0]->severity);
        self::assertStringContainsString('NakedCaller::handle', $findings[0]->message);
        self::assertStringContainsString('require_auth', $findings[0]->message);
    }

    // --------------------------------------------------------- sanitizer arm

    public function testSanitizerDivergenceNamesTheRawMember(): void
    {
        $findings = $this->analyzeFixture('sanitizer');

        self::assertCount(1, $findings);
        self::assertSame('drift-missing-sanitizer', $findings[0]->code);
        self::assertSame(Finding::SEVERITY_ERROR, $findings[0]->severity);
        self::assertStringContainsString('RawCaller::handle', $findings[0]->message);
        self::assertStringContainsString('real_escape', $findings[0]->message);
        self::assertStringContainsString('EscapedCaller::handle', $findings[0]->message);
    }

    public function testSanitizerControlOnUniformCluster(): void
    {
        self::assertSame([], $this->analyzeFixture('sanitizer_uniform'),
            'NEGATIVE CONTROL: both members route through the sanitizer');
    }

    // -------------------------------------------------------------- option arm

    public function testOptionDivergenceNamesTheSharedOption(): void
    {
        $findings = $this->analyzeFixture('options');

        self::assertCount(1, $findings);
        self::assertSame('drift-option-divergence', $findings[0]->code);
        self::assertSame(Finding::SEVERITY_NOTE, $findings[0]->severity);
        self::assertStringContainsString('CURLOPT_SSL_VERIFYPEER', $findings[0]->message);
        self::assertStringContainsString('false vs true', $findings[0]->message);
    }

    public function testOptionDivergenceStaysSilentWhenTheOptionNameIsUnresolvable(): void
    {
        // r1 m-2 scoping: cache-warm runs ship the cluster without its
        // generalizedAst, so the divergent position's NAME cannot be
        // resolved — and an unnamed constant cannot be proven
        // security-relevant. The arm earns no claim without the name
        // at hand (the note shape only exists for ENUMERATED options).
        $cluster = $this->unifyFixture('options');
        $cluster->generalizedAst = null;
        foreach ($cluster->members as $member) {
            $member->ast = null;
        }

        $findings = (new DriftAnalyzer(self::VOCAB))->analyze($cluster);

        $codes = array_map(static fn(Finding $f): string => $f->code, $findings);
        self::assertNotContains('drift-option-divergence', $codes,
            'no resolvable name means no scoping evidence — the arm must not fire on shape alone');
    }

    public function testOptionDivergenceRefusesANonEnumeratedOptionConstant(): void
    {
        // r1 m-2 scoping: a trio diverging on CURLOPT_RETURNTRANSFER —
        // real option-shaped arguments, but NOT a security-relevant
        // option from the vocabulary — must not earn the note. (The
        // odd-one-out predicate may still name the minority row: that
        // is honest value-drift, a different claim; what must be absent
        // is drift-option-divergence.)
        $on  = 'public function handle($ch) { curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); return $ch; }';
        $off = 'public function handle($ch) { curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); return $ch; }';
        $cluster = $this->inlineCluster([$on, $on, $off]);

        (new AntiUnifier(null, true, 3))->unify($cluster);

        $codes = array_map(
            static fn(Finding $f): string => $f->code,
            (new DriftAnalyzer(self::VOCAB))->analyze($cluster)
        );
        self::assertNotContains('drift-option-divergence', $codes,
            'off-vocabulary constants are not security-relevant option evidence (§4.1 letter)');
    }

    public function testOptionControlOnUniformCluster(): void
    {
        self::assertSame([], $this->analyzeFixture('options_uniform'),
            'NEGATIVE CONTROL: identical option flags never diverge');
    }

    // ------------------------------------------------------------ copy-paste

    public function testCopyPasteLiteralNamesTheCitingMemberOnce(): void
    {
        $findings = $this->analyzeFixture('literal');

        self::assertCount(1, $findings, 'only the row that cites another module is evidence');
        self::assertSame('drift-copy-paste-literal', $findings[0]->code);
        self::assertSame(Finding::SEVERITY_WARNING, $findings[0]->severity);
        self::assertStringContainsString('QuartzLog::handle', $findings[0]->message,
            'the quoting member is the one named');
        self::assertStringContainsString('ZirconLog', $findings[0]->message);
        self::assertStringNotContainsString("carries 'zircon module", $findings[0]->message,
            'the self-naming row is not copy-paste evidence');
    }

    public function testCopyPasteControlOnSelfNamingCluster(): void
    {
        self::assertSame([], $this->analyzeFixture('literal_uniform'),
            'NEGATIVE CONTROL: every string names its own module');
    }

    // ------------------------------------------------------------ odd-one-out

    public function testOddOneOutNamesTheMinorityHolder(): void
    {
        $findings = $this->analyzeFixture('odd');

        self::assertCount(1, $findings);
        self::assertSame('drift-odd-one-out', $findings[0]->code);
        self::assertSame(Finding::SEVERITY_WARNING, $findings[0]->severity);
        self::assertStringContainsString('OddMemberDelta::handle', $findings[0]->message);
        self::assertStringContainsString('network', $findings[0]->message);
        self::assertStringContainsString('agree on', $findings[0]->message);
    }

    public function testOddOneOutControlOnSplitCluster(): void
    {
        self::assertSame([], $this->analyzeFixture('odd_split'),
            'NEGATIVE CONTROL: a 2-vs-2 split is disagreement, not an odd one out');
    }

    // ------------------------------------------------------- posture & safety

    public function testAnalyzerIsInertWithoutAVocabulary(): void
    {
        // The default mount in ReportStage carries the empty vocab, so
        // every fixture — fire shapes included — must produce zero
        // findings. This is the Golden-freeze guarantee on the unit side.
        foreach ([
            'guard', 'guard_pair', 'guard_trio', 'guard_five', 'auth',
            'sanitizer', 'options', 'literal', 'odd', 'odd_split',
        ] as $fixture) {
            $cluster = $this->unifyFixture($fixture);
            self::assertSame([], (new DriftAnalyzer())->analyze($cluster),
                "the empty vocabulary must not enable {$fixture}");
        }
    }

    public function testFindingsSurviveTheSerializeRoundTrip(): void
    {
        // Socketpair proof on the analyzer's input surface: the holes
        // travel as scalars only, so a serialize/deserialize cycle (what
        // RefactorWorker ships) must not change a single finding.
        $cluster = $this->unifyFixture('guard');
        $before  = (new DriftAnalyzer(self::VOCAB))->analyze($cluster);

        $shipping      = new Cluster('round-trip', $cluster->members, $cluster->similarity, $cluster->exact);
        $shipping->holes     = unserialize(serialize($cluster->holes));
        $shipping->holePaths = $cluster->holePaths;

        $after = (new DriftAnalyzer(self::VOCAB))->analyze($shipping);

        self::assertNotSame([], $before, 'the cluster must actually fire for this to mean anything');
        self::assertSame(
            array_map(static fn(Finding $f): string => $f->code . '|' . $f->message, $before),
            array_map(static fn(Finding $f): string => $f->code . '|' . $f->message, $after)
        );
    }

    public function testPresenceOfAbsentWitnessIsTheOnlyAbsenceClaim(): void
    {
        // A hand-built hole whose MISSING rows carry NO witness (a legacy
        // or pad row) is ambiguous: the analyzer may not name anyone.
        $cluster = $this->unifyFixture('guard_pair');
        foreach ($cluster->holes as $hole) {
            foreach ($hole->observedValues as $i => $row) {
                if ($row === Hole::MISSING) {
                    $hole->descriptors[$i] = null; // erase the witness
                }
            }
        }

        self::assertSame([], (new DriftAnalyzer(self::VOCAB))->analyze($cluster),
            'unwitnessed absences license no claim');
    }

    // ---------------------------------------------------------------- plumbing

    /** @return list<Finding> */
    private function analyzeFixture(string $name, int $optionalCap = 3): array
    {
        return (new DriftAnalyzer(self::VOCAB))->analyze($this->unifyFixture($name, $optionalCap));
    }

    private function unifyFixture(string $name, int $optionalCap = 3): Cluster
    {
        $cluster = $this->loadFixtureCluster($name);
        (new AntiUnifier(null, true, $optionalCap))->unify($cluster);
        return $cluster;
    }

    /** @param list<string> $codes */
    private function inlineCluster(array $codes, string $prefix = 'Inline'): Cluster
    {
        $members = [];
        foreach ($codes as $i => $code) {
            $class   = $prefix . '_' . $i;
            $members[] = $this->methodBlock("<?php\nclass {$class} {\n    {$code}\n}", 'S' . $i, $class);
        }
        return new Cluster('T:' . $prefix, $members, 0.95, false);
    }

    private function loadFixtureCluster(string $name): Cluster
    {
        $dir     = dirname(__DIR__, 4) . '/tests/Fixtures/drift/' . $name;
        $members = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $members[] = $this->memberFromFile($file);
        }
        self::assertNotEmpty($members, "fixture {$name} produced no members");
        return new Cluster('D:' . $name, $members, 0.95, false);
    }

    private function memberFromFile(string $file): Block
    {
        $stmts = (new AstParser())->parseCode(file_get_contents($file));
        self::assertNotNull($stmts, "fixture {$file} failed to parse");

        $class  = (new NodeFinder())->findFirstInstanceOf($stmts, Node\Stmt\Class_::class);
        $method = (new NodeFinder())->findFirst($stmts, static fn(Node $n) => $n instanceof Node\Stmt\ClassMethod);
        self::assertInstanceOf(Node\Stmt\Class_::class, $class);
        self::assertInstanceOf(Node\Stmt\ClassMethod::class, $method);

        return $this->blockFor($file, (string) $class->name, $method);
    }

    private function methodBlock(string $code, string $file, string $class): Block
    {
        $stmts = (new AstParser())->parseCode($code);
        self::assertNotNull($stmts);
        $method = (new NodeFinder())->findFirst($stmts, static fn(Node $n) => $n instanceof Node\Stmt\ClassMethod);
        self::assertInstanceOf(Node\Stmt\ClassMethod::class, $method);

        return $this->blockFor($file, $class, $method);
    }

    private function blockFor(string $file, string $class, Node\Stmt\ClassMethod $method): Block
    {
        $block = new Block(
            $file,
            new LineRange($method->getStartLine(), $method->getEndLine()),
            'method',
            null,
            $class,
            $method->name->toString(),
            $method,
        );
        $block->id   = 'M:' . $file . ':' . $class;
        $block->size = AstSerializer::nodeCount($method);
        return $block;
    }
}
