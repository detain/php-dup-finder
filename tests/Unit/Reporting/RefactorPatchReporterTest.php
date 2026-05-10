<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\Hole;
use Phpdup\Reporting\RefactorPatchReporter;
use Phpdup\Reporting\RefactorTestReporter;
use Phpdup\Util\LineRange;

final class RefactorPatchReporterTest extends TestCase
{
    public function testPatchHeaderCarriesClusterMetadata(): void
    {
        $cluster = $this->cluster();
        $cluster->signature = "function f(int \$x): mixed";
        $patch = (new RefactorPatchReporter())->buildPatch($cluster);
        $this->assertStringContainsString("cluster {$cluster->id}", $patch);
        $this->assertStringContainsString("members: 2", $patch);
    }

    public function testPatchEmitsAddedAbstractionFile(): void
    {
        $cluster = $this->cluster();
        $cluster->signature = "function f(int \$x): mixed";
        $patch = (new RefactorPatchReporter())->buildPatch($cluster);
        $this->assertStringContainsString("Refactored/{$cluster->id}.php", $patch);
        $this->assertStringContainsString("new file mode 100644", $patch);
        $this->assertStringContainsString('TODO: implement', $patch);
    }

    public function testPatchSkipsWhenSignatureMissing(): void
    {
        $cluster = $this->cluster();
        $patch = (new RefactorPatchReporter())->buildPatch($cluster);
        $this->assertStringContainsString('No signature synthesised', $patch);
    }

    public function testTestSkeletonHasOneRowPerMember(): void
    {
        $cluster = $this->cluster();
        $cluster->holes = [new Hole('__P0', 'literal', ['1', '2'])];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);
        $this->assertStringContainsString("class Cluster{$cluster->id}Test", $skeleton);
        $this->assertStringContainsString('casesProvider', $skeleton);
        // Two member rows in the provider.
        $this->assertSame(2, substr_count($skeleton, "'member_"));
    }

    private function cluster(): Cluster
    {
        $blocks = [$this->blk('test1.php'), $this->blk('test2.php')];
        return new Cluster('TEST', $blocks, 1.0, false);
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
