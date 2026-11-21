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
