<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\ParamIntersection;
use Phpdup\Refactor\PromotedParam;
use Phpdup\Refactor\SignatureBuilder;

/**
 * output-S49a (php-dup-finder-output-006, half A): intersected shared
 * formal parameters of a cluster's members — derivation law + the
 * SignatureBuilder render that consumes it.
 *
 * The apply venue boundary is pinned here too: promotion never produces
 * holes and never touches holePaths, so the hole-driven rewrite stays
 * untouched until output-S49b wires the call sites.
 */
final class ParamIntersectionTest extends TestCase
{
    // ── the promotion accept-arm ──────────────────────────────────────

    public function testSharedFormalsArePromotedInDeclarationOrder(): void
    {
        $promoted = ParamIntersection::promote($this->members([
            'function notifyHigh($user, int $score) { return send($user, $score); }',
            'function notifyMid($user, int $score) { return relay($user, $score); }',
        ]));

        $this->assertSame([['user', null], ['score', 'int']], $this->shapes($promoted));
    }

    public function testAllMembersSharingEveryFormalPromoteTheWholeList(): void
    {
        $promoted = ParamIntersection::promote($this->members([
            'function findById(\PDO $db, int $id) { return null; }',
            'function findById(\PDO $db, int $id) { return false; }',
            'function findById(\PDO $db, int $id) { return []; }',
        ]));

        $this->assertSame([['db', '\PDO'], ['id', 'int']], $this->shapes($promoted));
    }

    public function testTypeEquivalenceIsNormalizedButDisplayKeepsFirstMemberSpelling(): void
    {
        $promoted = ParamIntersection::promote($this->members([
            'function f(INT $a, PDO $db) { return 1; }',
            'function f(int $a, \PDO $db) { return 2; }',
        ]));

        // Case-insensitive builtins and leading-backslash spelling are the
        // SAME type; the rendered text is the first member's own parsed
        // spelling (php-parser normalizes builtin type names to lowercase
        // at parse time, so 'INT' arrives as 'int'; class names keep case
        // and slash spelling).
        $this->assertSame([['a', 'int'], ['db', 'PDO']], $this->shapes($promoted));
    }

    public function testUnionPartsCompareAsSet(): void
    {
        $promoted = ParamIntersection::promote($this->members([
            'function f(int|string $a) { return 1; }',
            'function f(string|int $a) { return 2; }',
        ]));

        $this->assertSame([['a', 'int|string']], $this->shapes($promoted));
    }

    public function testClosureAndArrowFormalsPromote(): void
    {
        $closurePair = ParamIntersection::promote($this->members([
            '$a = function (string $s, int $n) { return 1; };',
            '$b = function (string $s, int $n) { return 2; };',
        ]));
        $this->assertSame([['s', 'string'], ['n', 'int']], $this->shapes($closurePair));

        $arrowPair = ParamIntersection::promote($this->members([
            '$a = fn(int $x) => $x * 2;',
            '$b = fn(int $x) => $x * 3;',
        ]));
        $this->assertSame([['x', 'int']], $this->shapes($arrowPair));
    }

    public function testBareDeclarationEvidenceIsAllPromotionNeeds(): void
    {
        // A degraded HOLE is incomplete value evidence; the member
        // declarations themselves were fully observed — S26 honesty cuts
        // for the hole, not for the shared formals.
        $members = $this->members([
            'function f($user) { return a($user); }',
            'function f($user) { return b($user); }',
        ]);
        $promoted = ParamIntersection::promote($members);

        $this->assertSame([['user', null]], $this->shapes($promoted));
    }

    // ── the prefix-stop arms (negative cases, finding 006 acceptance) ──

    public function testReorderedParamsAreNotPromoted(): void
    {
        // Same variables, different declaration positions: the positional
        // call-site law (S49b) could not bind them honestly.
        $promoted = ParamIntersection::promote($this->members([
            'function f($a, $b) { return 1; }',
            'function g($b, $a) { return 2; }',
        ]));

        $this->assertSame([], $this->shapes($promoted));
    }

    public function testExtraTrailingParamsStopThePrefixAtTheShortest(): void
    {
        $promoted = ParamIntersection::promote($this->members([
            'function f($a, $b, $c) { return 1; }',
            'function g($a, $b) { return 2; }',
        ]));

        $this->assertSame([['a', null], ['b', null]], $this->shapes($promoted));
    }

    public function testNameDivergenceStopsAfterTheSharedPrefix(): void
    {
        $promoted = ParamIntersection::promote($this->members([
            'function f($a, $b) { return 1; }',
            'function g($a, $c) { return 2; }',
        ]));

        $this->assertSame([['a', null]], $this->shapes($promoted));
    }

    public function testTypeDivergenceIsNotPromoted(): void
    {
        $this->assertSame([], $this->shapes(ParamIntersection::promote($this->members([
            'function f(int $a) { return 1; }',
            'function f(string $a) { return 2; }',
        ]))));

        // Untyped vs typed: passing what the untyped member's callers
        // pass would TypeError under strict_types.
        $this->assertSame([], $this->shapes(ParamIntersection::promote($this->members([
            'function f($a) { return 1; }',
            'function f(int $a) { return 2; }',
        ]))));
    }

    public function testNullableVsNonNullableIsNotPromoted(): void
    {
        $this->assertSame([], $this->shapes(ParamIntersection::promote($this->members([
            'function f(?int $a) { return 1; }',
            'function f(int $a) { return 2; }',
        ]))));
    }

    public function testByRefVariadicDefaultedAttrAndFlaggedParamsStopThePrefix(): void
    {
        $cases = [
            'byRef'    => ['function f(&$a) { return 1; }', 'function f(&$a) { return 2; }'],
            'variadic' => ['function f(int ...$a) { return 1; }', 'function f(int ...$a) { return 2; }'],
            'default'  => ['function f(int $a = 1) { return 1; }', 'function f(int $a = 1) { return 2; }'],
            'attr'     => ['function f(#[Sold] $a) { return 1; }', 'function f(#[Sold] $a) { return 2; }'],
            'flagged'  => [
                'class C1 { function __construct(private int $x) {} }',
                'class C2 { function __construct(private int $x) {} }',
            ],
        ];
        foreach ($cases as $label => $codes) {
            $this->assertSame([], $this->shapes(ParamIntersection::promote($this->members($codes))), $label);
        }
    }

    public function testClassContextTypesAreRefused(): void
    {
        // self/parent resolve against the declaring class — the extracted
        // artifact is a plain function (ARTIFACT_NAMESPACE). ('static $x'
        // is not expressible as a param type: reserved-word grammar.)
        foreach (['self', 'parent'] as $type) {
            $promoted = ParamIntersection::promote($this->membersClassScoped($type));
            $this->assertSame([], $this->shapes($promoted), $type);
        }
    }

    public function testVariableVariableParamIsNotPromoted(): void
    {
        // Hand-built AST: `function f($$k)` is refused by php-parser's
        // grammar, but a Variable(Expr) param can still arrive from an
        // unloaded/reparsed tree — paramName() must refuse the slot.
        $weird = new \Phpdup\Extraction\Block(
            'weird.php',
            new \Phpdup\Util\LineRange(1, 3),
            'function',
            null,
            null,
            'f',
            new \PhpParser\Node\Stmt\Function_(
                'f',
                [
                    'params' => [new \PhpParser\Node\Param(
                        new \PhpParser\Node\Expr\Variable(
                            new \PhpParser\Node\Expr\ConstFetch(new \PhpParser\Node\Name('k'))
                        )
                    )],
                    'stmts'  => [new \PhpParser\Node\Stmt\Return_(new \PhpParser\Node\Scalar\Int_(1))],
                ]
            )
        );
        $ok = $this->members(['function f($k) { return 1; }'])[0];

        $this->assertSame([], $this->shapes(ParamIntersection::promote([$weird, $ok])));
        $this->assertSame([], $this->shapes(ParamIntersection::promote([$ok, $weird])));
    }

    public function testReservedHoleNameCollisionStopsThePrefix(): void
    {
        // A hole already renders $score — the two claims cannot share one
        // signature; the prefix stops before the collision.
        $members = $this->members([
            'function f($score, $user) { return 1; }',
            'function f($score, $user) { return 2; }',
        ]);

        $this->assertSame([], $this->shapes(ParamIntersection::promote($members, ['$score'])));
        $this->assertSame([['score', null]], $this->shapes(ParamIntersection::promote($members, ['$user'])));
    }

    // ── evidence refusals ──────────────────────────────────────────────

    public function testSingleMemberAndEmptyClustersPromoteNothing(): void
    {
        $one = $this->members(['function f($a) { return 1; }']);
        $this->assertSame([], $this->shapes(ParamIntersection::promote($one)));
        $this->assertSame([], $this->shapes(ParamIntersection::promote([])));
    }

    public function testRoutedClusterPromotesNothing(): void
    {
        // output-S57: more members than representatives means a tail that
        // was never walked — "every member declares this" is unobserved.
        $codes = array_fill(0, \Phpdup\Refactor\AntiUnifier::MAX_UNIFY_MEMBERS + 1,
            'function f(int $shared) { return 1; }');
        $members = $this->members($codes);

        $this->assertCount(\Phpdup\Refactor\AntiUnifier::MAX_UNIFY_MEMBERS + 1, $members);
        $this->assertSame([], $this->shapes(ParamIntersection::promote($members)));
    }

    public function testUnloadedMemberAstPromotesNothing(): void
    {
        $members = $this->members([
            'function f($a) { return 1; }',
            'function f($a) { return 2; }',
        ]);
        $members[1]->unloadAst();

        $this->assertSame([], $this->shapes(ParamIntersection::promote($members)));
    }

    public function testNonSignatureBearingRootsPromoteNothing(): void
    {
        // 'if'-kind blocks (the optional-near-dups golden family) carry no
        // formal-parameter region at all — today's shape is preserved.
        $all = $this->allMembers([
            'function outer($x) { if ($x == 1) { do_a($x); } }',
            'function outer2($y) { if ($y == 2) { do_b($y); } }',
        ]);
        // Keep only the if-kind blocks (the enclosing functions are also
        // members of the extract set).
        $ifs = array_values(array_filter($all, static fn(Block $b): bool => $b->kind === 'if'));
        $this->assertNotEmpty($ifs);

        $this->assertSame([], $this->shapes(ParamIntersection::promote($ifs)));
    }

    // ── SignatureBuilder consumption ──────────────────────────────────

    public function testSignatureBuilderLeadsWithThePromotedFormals(): void
    {
        $cluster = $this->cluster($this->members([
            'function notifyHigh($user, int $score) { if ($score > 10) { send("admin", $user); } }',
            'function notifyMid($user, int $score) { if ($score > 20) { send("mod", $user); } }',
        ]));
        (new \Phpdup\Refactor\AntiUnifier())->unify($cluster);
        (new \Phpdup\Refactor\ParameterSynthesizer())->synthesize($cluster);

        (new SignatureBuilder())->buildSignature($cluster);

        // Promoted shared formals first, in declaration order, then the
        // hole params exactly as today.
        $this->assertSame(
            "function notifyByThreshold(\n    \$user,\n    int \$score,\n    int \$threshold,\n    string \$value,\n): mixed",
            (string)$cluster->signature,
        );
    }

    public function testSignatureWithoutSharedFormalsKeepsTodayShape(): void
    {
        $cluster = $this->cluster($this->members([
            'function notifyHigh($user, int $score) { if ($score > 10) { send("admin", $user); } }',
            'function notifyMid($other, int $score) { if ($score > 20) { send("mod", $other); } }',
        ]));
        (new \Phpdup\Refactor\AntiUnifier())->unify($cluster);
        (new \Phpdup\Refactor\ParameterSynthesizer())->synthesize($cluster);

        (new SignatureBuilder())->buildSignature($cluster);

        // Position 0 names differ → the prefix dies immediately; $score at
        // position 1 is NOT promoted across the dead stop (positional law).
        $sig = (string)$cluster->signature;
        $this->assertStringNotContainsString('$score,', $sig);
        $this->assertStringContainsString('int $threshold,', $sig);
    }

    public function testZeroHoleSharedFormalClusterGainsThePromotedParameters(): void
    {
        // The finding's symptom: a zero-hole extraction whose body reads
        // the members' locals. With derivation the advisory now carries
        // them; the cluster stays hole-free and holePaths stays empty —
        // the apply venue refuses zero-hole clusters up front (S44
        // entry guard) and the hole-driven rewrite never sees a promoted
        // parameter until output-S49b wires the call sites.
        $cluster = $this->cluster($this->members([
            'function f(int $x) { return $x; }',
            'function f(int $x) { return $x; }',
        ]));
        (new \Phpdup\Refactor\AntiUnifier())->unify($cluster);
        (new \Phpdup\Refactor\ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame([], $cluster->holes);
        $this->assertSame([], $cluster->holePaths);
        (new SignatureBuilder())->buildSignature($cluster);

        $this->assertSame("function f(\n    int \$x,\n): mixed", (string)$cluster->signature);
    }

    public function testPromotedSignatureIsValidPhp(): void
    {
        $cluster = $this->cluster($this->members([
            'function f($user, int $score) { return 1; }',
            'function f($user, int $score) { return 2; }',
        ]));
        (new SignatureBuilder())->buildSignature($cluster);

        $code = "<?php\n" . (string)$cluster->signature . " { return 1; }\n";
        $file = tempnam(sys_get_temp_dir(), 'sig') . '.php';
        file_put_contents($file, $code);
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $rc);
        unlink($file);

        $this->assertSame(0, $rc, implode("\n", $output));
    }

    public function testHoleNamesCannotShadowPromotedNames(): void
    {
        // Hand-built: a hole renders $score, a member declares $score at
        // position 0 — the collision stops the prefix, one variable per
        // slot.
        $members = $this->members([
            'function f($score) { return 1; }',
            'function f($score) { return 2; }',
        ]);
        $cluster = $this->cluster($members);
        $hole = new Hole('__P0', 'literal', ['10', '20']);
        $hole->suggestedName = '$score';
        $hole->inferredType  = 'int';
        $cluster->holes = [$hole];

        (new SignatureBuilder())->buildSignature($cluster);

        $sig = (string)$cluster->signature;
        $this->assertSame(1, substr_count($sig, '$score'), $sig);
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @param list<PromotedParam> $promoted @return list<array{0:string,1:?string}> */
    private function shapes(array $promoted): array
    {
        return array_map(
            static fn(PromotedParam $p): array => [$p->name, $p->type],
            $promoted,
        );
    }

    /**
     * Parse each snippet and return every block the extractor mints from
     * it (functions, closures via assignment, ifs, classes' methods...).
     *
     * @param list<string> $codes
     * @return list<Block>
     */
    private function members(array $codes): array
    {
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks    = [];
        foreach ($codes as $i => $code) {
            $stmts     = $parser->parseCode('<?php ' . $code);
            $extracted = $extractor->extract("test{$i}.php", $stmts);
            $this->assertNotEmpty($extracted, $code);
            // The outermost block only (same convention as the
            // AntiUnifierTest fixtures): functions, closures, arrows —
            // nested regions of a member are not cluster members.
            $blocks[] = $extracted[0];
        }
        return $blocks;
    }

    /**
     * Parse a snippet and return ALL extracted blocks (outermost first).
     *
     * @param list<string> $codes
     * @return list<Block>
     */
    private function allMembers(array $codes): array
    {
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks    = [];
        foreach ($codes as $i => $code) {
            $stmts     = $parser->parseCode('<?php ' . $code);
            $extracted = $extractor->extract("test{$i}.php", $stmts);
            $this->assertNotEmpty($extracted, $code);
            foreach ($extracted as $block) {
                $blocks[] = $block;
            }
        }
        return $blocks;
    }

    /**
     * @param list<Block> $blocks
     */
    private function cluster(array $blocks): Cluster
    {
        return new Cluster('TEST', $blocks, 1.0, false);
    }

    /**
     * Two class methods declaring `PUBLIC-function f(<type> $a)`.
     *
     * @return list<Block>
     */
    private function membersClassScoped(string $type): array
    {
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks    = [];
        foreach ([['C1', 'a'], ['C2', 'b']] as [$class, $body]) {
            $stmts = $parser->parseCode(
                "<?php class {$class} { public function f({$type} \$x) { return {$body}; } }"
            );
            $extracted = $extractor->extract("c{$class}.php", $stmts);
            $methods = array_values(array_filter(
                $extracted,
                static fn(Block $b): bool => $b->kind === 'method',
            ));
            $this->assertNotEmpty($methods);
            $blocks[] = $methods[0];
        }
        return $blocks;
    }
}
