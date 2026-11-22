<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\Hole;
use Phpdup\Reporting\RefactorTestReporter;
use Phpdup\Util\LineRange;

final class RefactorTestReporterTest extends TestCase
{
    public function testGeneratedMethodArityMatchesProviderRows(): void
    {
        $cluster = $this->clusterWithThreeHoles();
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        // Extract the method signature line
        if (preg_match('/public function testAbstractionMatchesEachMember\(([^)]*)\)/', $skeleton, $matches)) {
            $paramList = $matches[1];
            // Count parameters by splitting on commas ( accounting for "Type $name" pairs )
            $paramCount = $paramList === '' ? 0 : count(preg_split('/,\s*/', $paramList));
            $this->assertSame(3, $paramCount, 'Method arity must match hole count: ' . $paramList);
        } else {
            $this->fail('Could not find testAbstractionMatchesEachMember method signature in generated code');
        }
    }

    public function testGeneratedCodePassesPhpLint(): void
    {
        $cluster = $this->clusterWithThreeHoles();
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $tmpFile = sys_get_temp_dir() . '/phpdup_test_' . uniqid() . '.php';
        try {
            file_put_contents($tmpFile, $skeleton);
            $result = shell_exec("php -l " . escapeshellarg($tmpFile));
            $this->assertStringContainsString('No syntax errors', $result ?? '');
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testGeneratedCodeWithZeroHolesIsValid(): void
    {
        $cluster = $this->clusterWithNoHoles();
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $this->assertStringContainsString('public function testAbstractionMatchesEachMember(', $skeleton);

        $tmpFile = sys_get_temp_dir() . '/phpdup_test_' . uniqid() . '.php';
        try {
            file_put_contents($tmpFile, $skeleton);
            $result = shell_exec("php -l " . escapeshellarg($tmpFile));
            $this->assertStringContainsString('No syntax errors', $result ?? '');
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testSuggestedNameUsedWhenAvailable(): void
    {
        $cluster = $this->clusterWithThreeHoles();
        // Set suggested names on holes
        $cluster->holes[0]->suggestedName = 'userId';
        $cluster->holes[1]->suggestedName = 'recordCount';
        $cluster->holes[2]->suggestedName = 'isActive';

        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $this->assertStringContainsString('$userId', $skeleton);
        $this->assertStringContainsString('$recordCount', $skeleton);
        $this->assertStringContainsString('$isActive', $skeleton);
    }

    public function testPlaceholderUsedWhenSuggestedNameEmpty(): void
    {
        $cluster = $this->clusterWithThreeHoles();
        // Leave suggestedName empty, use placeholder
        $this->assertSame('', $cluster->holes[0]->suggestedName);
        $this->assertSame('', $cluster->holes[1]->suggestedName);
        $this->assertSame('', $cluster->holes[2]->suggestedName);

        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        // Should use placeholders like $__P0, $__P1, $__P2
        $this->assertStringContainsString('$__P0', $skeleton);
        $this->assertStringContainsString('$__P1', $skeleton);
        $this->assertStringContainsString('$__P2', $skeleton);
    }

    public function testInferredTypeUsedInParameter(): void
    {
        $cluster = $this->clusterWithThreeHoles();
        $cluster->holes[0]->inferredType = 'int';
        $cluster->holes[1]->inferredType = 'string';
        $cluster->holes[2]->inferredType = 'bool';

        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $this->assertStringContainsString('int $', $skeleton);
        $this->assertStringContainsString('string $', $skeleton);
        $this->assertStringContainsString('bool $', $skeleton);
    }

    public function testProviderRowsCountMatchesMembers(): void
    {
        $cluster = $this->clusterWithThreeHoles();
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        // Two members → two rows in provider
        $this->assertSame(2, substr_count($skeleton, "'member_"));
    }

    public function testProviderRowValuesMatchHoleCount(): void
    {
        $cluster = $this->clusterWithThreeHoles();
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        // Each row should have 3 values (one per hole)
        if (preg_match_all("/'member_[^']+' => \[([^\]]+)\]/", $skeleton, $matches)) {
            foreach ($matches[1] as $rowValues) {
                $valueCount = count(array_map('trim', explode(',', $rowValues)));
                $this->assertSame(3, $valueCount, "Each provider row must have 3 values, got: {$rowValues}");
            }
        } else {
            $this->fail('Could not find provider rows in generated code');
        }
    }

    public function testDegradedGapRendersAnnotatedNullNeverSilentEmptyString(): void
    {
        // M2 (review r1): degraded holes carry '' in memory — the old null
        // arm never fired for them and the quote fallback shipped a silent
        // '' provider row where JSON says degraded:true. The fix renders an
        // annotated null: parseable AND honest.
        $cluster = $this->clusterWithNoHoles();
        $cluster->holes = [new Hole('__P0', 'literal', ['10', Hole::MISSING], true)];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $this->assertStringContainsString(
            'null /* degraded: observed value was a structural gap */',
            $skeleton,
        );

        // The gapped MEMBER's row shows the marker; its value list must not
        // contain a bare '' entry anywhere.
        $rows = preg_split('/\r?\n/', $skeleton);
        $gapRow = null;
        $cleanRow = null;
        foreach ($rows as $line) {
            if (str_contains($line, 'member_1_')) {
                $gapRow = $line;
            } elseif (str_contains($line, 'member_0_')) {
                $cleanRow = $line;
            }
        }
        $this->assertNotNull($gapRow, 'provider must carry a row per member');
        $this->assertStringContainsString('degraded', (string) $gapRow);
        preg_match("/'member_1_[^']*'\s*=>\s*\[([^\]]*)\]/", (string) $gapRow, $m);
        $this->assertArrayHasKey(1, $m, 'row values parseable');
        $this->assertStringNotContainsString("''", $m[1], 'no silent empty-string provider value');
        $this->assertStringNotContainsString('degraded', (string) $cleanRow, 'clean slots stay plain');
        $this->assertStringContainsString('10', (string) $cleanRow);

        // And the artifact still lints.
        $tmpFile = sys_get_temp_dir() . '/phpdup_test_' . uniqid() . '.php';
        try {
            file_put_contents($tmpFile, $skeleton);
            $result = shell_exec('php -l ' . escapeshellarg($tmpFile));
            $this->assertStringContainsString('No syntax errors', $result ?? '');
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testLegacyMissingStringTakesTheSameAnnotatedNullArm(): void
    {
        // Belt parity with JsonReporter: pre-5.4 cached holes may still
        // carry the '<missing>' impostor string — never ship it as data.
        $cluster = $this->clusterWithNoHoles();
        $cluster->holes = [new Hole('__P0', 'literal', ['<missing>', "'kept'"], true)];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $this->assertStringContainsString('null /* degraded', $skeleton);
        $this->assertStringNotContainsString('<missing>', $skeleton);
    }

    public function testCanonicalAbsenceRowOnHonestOptionalHoleProjectsFalse(): void
    {
        // output-S27: the canonical absence row on an optional hole whose
        // evidence is complete is a VERIFIED absence — the retired printable
        // sentinel's exact meaning — so the provider row says `false`, not
        // the degraded annotation.
        $cluster = $this->clusterWithNoHoles();
        $cluster->holes = [new Hole('__O0', 'optional_block', ["'x'", Hole::MISSING], false)];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $this->assertStringNotContainsString('degraded', $skeleton, 'verified absence carries no degradation');
        $rows = preg_split('/\r?\n/', $skeleton);
        $absentRow = null;
        $valueRow  = null;
        foreach ($rows as $line) {
            if (str_contains($line, 'member_1_')) {
                $absentRow = $line;
            } elseif (str_contains($line, 'member_0_')) {
                $valueRow = $line;
            }
        }
        $this->assertNotNull($absentRow, 'provider must carry a row per member');
        $this->assertStringContainsString('false', (string) $absentRow, 'verified-absent member row passes false');
        // output-S35 re-pin (php-dup-finder-output-007): this row asserted
        // the raw quoted shape `'x'`, which ONLY existed because the retired
        // quote-shaped passthrough re-interpreted the observed repr as code
        // (the string `'x'` reached the provider as the 1-char value x — the
        // sink's benign face). Under the literal doctrine the member keeps
        // its value BYTE-HONEST as data: var_export of the observed repr.
        $this->assertStringContainsString(<<<'PHP'
'\'x\''
PHP, (string) $valueRow, 'the observed member keeps its value as an inert literal');
    }

    public function testAmbiguousAbsenceRowOnDegradedOptionalHoleStaysAnnotatedNull(): void
    {
        // The other half of the S27 projection law: once the hole is
        // degraded, a canonical '' row may be a never-observed gap — the
        // generator must NOT claim `false` on ambiguous evidence.
        $cluster = $this->clusterWithNoHoles();
        $cluster->holes = [new Hole('__O0', 'optional_block', ["'x'", Hole::MISSING], true)];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $this->assertStringContainsString('null /* degraded', $skeleton);
        $this->assertStringNotContainsString("'x', false", $skeleton);
    }

    // ── output-035 (S6): the cluster-name metadata row defuses ────────────────

    public function testClusterNameMetadataRowDefusesFormulaTrigger(): void
    {
        $cluster = new Cluster("=cmd|'/C calc'", [$this->blk('t1.php'), $this->blk('t2.php')], 1.0, false);
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $this->assertStringContainsString("Auto-generated by phpdup for cluster '=cmd|'/C calc'.", $skeleton);
        // Code positions stay raw by the carve-out law: a class name is an
        // identifier slot, not a spreadsheet cell — defusing it would break
        // the lint pins this reporter's output contract depends on.
        $this->assertStringNotContainsString("class Cluster'=cmd", $skeleton);
    }

    public function testProviderLabelsAreStructurallyDefusedAtRowStart(): void
    {
        // Regression pin for the label analysis: every provider row already
        // leads with `'member_` — the defuse prefix itself — so a trigger
        // char inside the embedded path is inert and must NOT get a second,
        // mid-token `'`.
        $cluster = new Cluster('DEFUSE', [$this->blk('=evil.php'), $this->blk('t2.php')], 1.0, false);
        $cluster->holes = [new Hole('__P0', 'literal', ['1', '2'])];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $this->assertMatchesRegularExpression("/^ +'member_0_=evil\.php/m", $skeleton);
        $this->assertStringNotContainsString("member_0_'=", $skeleton);
    }

    // ── r2 N-1: the header is CODE — ugly display fields must project sanely ──

    public function testSigilCarryingSuggestedNamesRenderSingleDollarHeaders(): void
    {
        $cluster = $this->clusterWithThreeHoles();
        // Exactly what ParameterSynthesizer actually mints in production:
        // names WITH the sigil and PHPDoc spellings for the type.
        $cluster->holes[0]->suggestedName = '$threshold';
        $cluster->holes[0]->inferredType  = 'int';
        $cluster->holes[1]->suggestedName = '$arg';
        $cluster->holes[1]->inferredType  = 'class-string';
        $cluster->holes[2]->suggestedName = '$value';
        $cluster->holes[2]->inferredType  = 'callable|string';

        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $this->assertStringContainsString(
            'int $threshold, string $arg, callable|string $value',
            $skeleton,
            'sigil passthrough + code-safe type mapping',
        );
        $this->assertStringNotContainsString('$$', $skeleton);
        $this->assertStringNotContainsString('class-string $', $skeleton);
        $this->assertLintClean($skeleton);
    }

    public function testCleanNameHeaderBytesAreUnchangedFromLegacyFormat(): void
    {
        $cluster = $this->clusterWithThreeHoles();
        $cluster->holes[0]->suggestedName = '$threshold';
        $cluster->holes[0]->inferredType  = 'int';
        $cluster->holes[1]->suggestedName = '$value';
        $cluster->holes[1]->inferredType  = 'string';

        $skeleton = (new RefactorTestReporter())->buildTest($cluster);
        // The pre-fix render for ALREADY-VALID inputs was "{$type} \${$name}";
        // the projector must reproduce it byte-for-byte.
        $this->assertMatchesRegularExpression(
            '/public function testAbstractionMatchesEachMember\(int \$threshold, string \$value, mixed \$__P2\): void/',
            $skeleton,
        );
    }

    public function testThisBindingFallsBackToPlaceholderAndStaysLintClean(): void
    {
        $cluster = $this->clusterWithThreeHoles();
        $cluster->holes[0]->suggestedName = '$this'; // PHP's one forbidden binding

        $skeleton = (new RefactorTestReporter())->buildTest($cluster);
        $this->assertStringNotContainsString('$this,', $skeleton);
        $this->assertStringContainsString('mixed $__P0', $skeleton);
        $this->assertLintClean($skeleton);
    }

    public function testPlainClassVariableNameIsLegalAndStaysUntouched(): void
    {
        // Measured truth, not folklore: the corpus deaths at token "class"
        // came from the TYPE class-string, never from a variable called
        // $class — that is a perfectly legal PHP parameter binding.
        $cluster = $this->clusterWithThreeHoles();
        $cluster->holes[0]->suggestedName = '$class';
        $cluster->holes[0]->inferredType  = 'object';

        $skeleton = (new RefactorTestReporter())->buildTest($cluster);
        $this->assertStringContainsString('object $class', $skeleton);
        $this->assertLintClean($skeleton);
    }

    public function testSanitizerCollisionsGetDeterministicPositionalSuffixes(): void
    {
        $cluster = $this->clusterWithThreeHoles();
        $cluster->holes[0]->suggestedName = '$foo.bar';
        $cluster->holes[1]->suggestedName = '$foo-bar'; // both sanitize to foo_bar

        $skeleton = (new RefactorTestReporter())->buildTest($cluster);
        $this->assertStringContainsString('mixed $foo_bar, mixed $foo_bar_2', $skeleton);
        $this->assertLintClean($skeleton);
    }

    // ── output-S35 (php-dup-finder-output-007, CRITICAL): quote-shaped text
    //    is not code — every provider row value is a literal ───────────────

    public function testExpressionShapedObservedValueEmitsAsVarExportDataNotRawCode(): void
    {
        // The SEC repro verbatim in shape: prettyPrintExpr of ANY concat
        // starts and ends on matching quotes, so the retired
        // `^(['"]).*\1$/s` passthrough emitted this observed value as RAW
        // provider code — and PHPUnit evaluates static provider arrays at
        // COLLECTION time, i.e. `--refactor-tests` on a hostile tree was
        // code execution, not report writing.
        $v = "'' . touch('/tmp/phpdup-s35-unit-canary') . ''";
        @unlink('/tmp/phpdup-s35-unit-canary');
        $cluster = $this->clusterWithNoHoles();
        $cluster->holes = [new Hole('__P0', 'subtree', [$v, "'y'"])];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        // 1. The acceptance pin: the value emits as a var_export'd string.
        $this->assertStringContainsString(<<<'PHP'
'\'\' . touch(\'/tmp/phpdup-s35-unit-canary\') . \'\''
PHP, $skeleton);

        // 2. The injection shape is GONE: no row may open its array and
        //    immediately hand the payload back as code.
        $this->assertStringNotContainsString("=> ['' . touch", $skeleton);

        // 3. Round-trip honesty: decoding the emitted literal yields the
        //    observed value byte-for-byte — data, no side effect.
        $literal = $this->firstProviderRowValue($skeleton);
        $decoded = eval('return ' . $literal . ';');
        $this->assertSame($v, $decoded, 'var_export round-trips the observed repr');
        $this->assertFileDoesNotExist('/tmp/phpdup-s35-unit-canary', 'collection must not execute the payload');
        @unlink('/tmp/phpdup-s35-unit-canary'); // mutant runs create it; keep /tmp tidy

        $this->assertLintClean($skeleton);
    }

    public function testQuoteShapedPlainLiteralStaysDataAndIsNotReinterpreted(): void
    {
        // The sink's benign face: an observed string-literal repr `'x'`
        // used to pass through raw, silently re-typing the 3-char observed
        // repr into the 1-char value x at provider collection. Honesty now
        // precedes prettiness: the row carries the observed bytes as data.
        $cluster = $this->clusterWithNoHoles();
        $cluster->holes = [new Hole('__P0', 'literal', ["'x'", "'y'"])];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $decoded = eval('return ' . $this->firstProviderRowValue($skeleton) . ';');
        $this->assertSame("'x'", $decoded, 'the observed repr survives intact as a string');
        $this->assertLintClean($skeleton);
    }

    public function testBareArmsStayAndOnlyTheQuoteHeuristicDied(): void
    {
        // Arm-boundary pin (behavior-unchanged codification): the two bare
        // arms take CLOSED token classes that cannot carry expression
        // syntax; the quote-shaped arm is the one that was removed.
        // (Pairs, not keys: PHP casts numeric-string array keys to int.)
        $cases = [
            ['42', '42'],                                    // int repr → bare
            ['-1.5', '-1.5'],                                // float repr → bare
            ['true', 'true'],                                // closed keyword list
            ['false', 'false'],
            ['null', 'null'],
            ['1e3', "'1e3'"],                                // outside the digit class → literal
            ['0x1F', "'0x1F'"],                              //   (S28 owns type fidelity)
            ['$var', "'\$var'"],                             // identifier repr → literal
        ];
        foreach ($cases as [$observed, $expectedEmit]) {
            $cluster = $this->clusterWithNoHoles();
            $cluster->holes = [new Hole('__P0', 'literal', [$observed, $observed])];
            $skeleton = (new RefactorTestReporter())->buildTest($cluster);
            // One hole → one value per member row.
            $this->assertStringContainsString(
                "[$expectedEmit],",
                $skeleton,
                "arm boundary for observed value {$observed}",
            );
        }
    }

    public function testControlBytesAreScrubbedFromRowValuesAndLabels(): void
    {
        // Util\ControlText doctrine (output-S18 venue, named consumer
        // -S35): the four stream-forging bytes may not ride into a
        // generated file — not in a row value, not smuggled through the
        // member path embedded in the provider label.
        $cluster = new Cluster('CTL_S35', [$this->blk("evil\nname.php"), $this->blk('t2.php')], 1.0, false);
        $cluster->holes = [new Hole('__P0', 'literal', ["a\nb\0c\rd\x1be", "'clean'"])];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $this->assertStringNotContainsString("\x1b", $skeleton, 'ESC must not survive into the artifact');
        $this->assertStringNotContainsString("\0", $skeleton, 'NUL must not survive into the artifact');

        // The gapped member's VALUE row is one physical line: LF/CR inside
        // the quoted literal are substituted, not shipped as raw breaks.
        $valueLine = $this->providerRowLine($skeleton, 'member_0_');
        $this->assertMatchesRegularExpression('/member_0_evil' . preg_quote("\u{FFFD}", '/') . 'name/', $valueLine, 'label carries the scrub marker');
        $this->assertStringContainsString("\u{FFFD}", $valueLine, 'row value carries the scrub marker');

        $decoded = $this->evalRowValues($skeleton, 'member_0_');
        $this->assertSame("a\u{FFFD}b\u{FFFD}c\u{FFFD}d\u{FFFD}e", $decoded[0], 'value round-trips scrubbed');
        $this->assertStringNotContainsString("\x1b", $decoded[0]);

        $this->assertLintClean($skeleton);
    }

    /** First `=> [v, ...]` row value token of the provider. */
    private function firstProviderRowValue(string $skeleton): string
    {
        $this->assertSame(
            1,
            preg_match("/=> \\[('(?:\\\\.|[^'\\\\])*')/", $skeleton, $m),
            'a string-literal row value must be extractable',
        );
        return $m[1];
    }

    /** @return string the full physical line of the provider row. */
    private function providerRowLine(string $skeleton, string $labelPrefix): string
    {
        foreach (preg_split('/\r?\n/', $skeleton) as $line) {
            if (str_contains($line, $labelPrefix)) {
                return $line;
            }
        }
        $this->fail("row {$labelPrefix} missing from the provider");
    }

    /** @return list<string> every string row value on that row, eval'd. */
    private function evalRowValues(string $skeleton, string $labelPrefix): array
    {
        $line = $this->providerRowLine($skeleton, $labelPrefix);
        $this->assertSame(1, preg_match('/=> \[(.*)\],?$/', $line, $m), 'row array extractable');
        $out = [];
        foreach (explode(',', $m[1]) as $token) {
            $token = trim($token);
            if ($token !== '' && $token[0] === "'") {
                $out[] = (string) eval('return ' . $token . ';');
            }
        }
        return $out;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function assertLintClean(string $skeleton): void
    {
        $tmpFile = sys_get_temp_dir() . '/phpdup_r2pin_' . uniqid() . '.php';
        try {
            file_put_contents($tmpFile, $skeleton);
            $result = shell_exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1');
            $this->assertStringContainsString('No syntax errors', $result ?? '', 'skeleton must parse');
        } finally {
            @unlink($tmpFile);
        }
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function clusterWithThreeHoles(): Cluster
    {
        $blocks = [$this->blk('test1.php'), $this->blk('test2.php')];
        $cluster = new Cluster('THREE_HOLE_TEST', $blocks, 0.85, false);
        $cluster->holes = [
            new Hole('__P0', 'literal', ['1', '2']),
            new Hole('__P1', 'identifier', ['$a', '$b']),
            new Hole('__P2', 'name', ['foo', 'bar']),
        ];
        return $cluster;
    }

    private function clusterWithNoHoles(): Cluster
    {
        $blocks = [$this->blk('test1.php'), $this->blk('test2.php')];
        return new Cluster('NO_HOLES_TEST', $blocks, 1.0, false);
    }

    private function blk(string $file): Block
    {
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode('<?php $x;');
        return new Block(
            file: $file,
            range: new LineRange(1, 1),
            kind: 'method',
            namespace: null, class: null, name: null,
            ast: $stmts[0],
        );
    }
}
