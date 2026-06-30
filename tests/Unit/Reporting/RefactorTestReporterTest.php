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
