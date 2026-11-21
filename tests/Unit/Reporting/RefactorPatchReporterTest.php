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

    public function testGuidanceNamesTheDeclaredFunctionNotAClusterGhost(): void
    {
        // M4: the shipped declaration is the (registry-resolved) signature
        // name; the member hints must point at exactly that name — a human
        // following the guidance verbatim must not call an undefined ghost.
        $a = $this->cluster();
        $a->signature = "function sharedThing(\$v): mixed";
        $b = $this->namedCluster('OTHER');
        $b->signature = "function sharedThing(\$v): mixed";
        (new \Phpdup\Refactor\FunctionNameRegistry())->resolve([$a, $b]);

        $patchA = (new RefactorPatchReporter())->buildPatch($a);
        $patchB = (new RefactorPatchReporter())->buildPatch($b);

        $this->assertSame('sharedThing', $this->declarationName($patchA));
        $this->assertSame('sharedThing_OTHER', $this->declarationName($patchB), 'loser carries its content-id suffix');
        $this->assertCountAtLeast2($this->guidanceNames($patchA));
        $this->assertSame(
            array_fill(0, count($this->guidanceNames($patchA)), $this->declarationName($patchA)),
            $this->guidanceNames($patchA),
        );
        $this->assertSame(
            array_fill(0, count($this->guidanceNames($patchB)), $this->declarationName($patchB)),
            $this->guidanceNames($patchB),
        );
        $this->assertStringNotContainsString('f_' . $b->id, $patchB, 'the f_{clusterId} ghost is gone');
    }

    /** @param list<string> $names */
    private function assertCountAtLeast2(array $names): void
    {
        $this->assertGreaterThanOrEqual(2, count($names), 'one hint per member');
    }

    private function declarationName(string $patch): ?string
    {
        return preg_match('/^\+function ([a-zA-Z_][a-zA-Z0-9_]*)\(/m', $patch, $m) === 1 ? $m[1] : null;
    }

    /** @return list<string> names the guidance tells the human to call */
    private function guidanceNames(string $patch): array
    {
        preg_match_all('/return \\\\Refactored\\\\([a-zA-Z_][a-zA-Z0-9_]*)\(/', $patch, $m);
        return $m[1];
    }

    private function namedCluster(string $id): Cluster
    {
        $blocks = [$this->blk('t1.php'), $this->blk('t2.php')];
        return new Cluster($id, $blocks, 1.0, false);
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
