<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\CoherenceAnalyzer;
use Phpdup\Util\LineRange;

final class CoherenceAnalyzerTest extends TestCase
{
    public function testNoOutliersWhenAllMembersHaveIdenticalBags(): void
    {
        $bag = ['a' => 1, 'b' => 1, 'c' => 1];
        $cluster = $this->cluster([
            $this->blk($bag), $this->blk($bag), $this->blk($bag),
        ]);
        (new CoherenceAnalyzer())->analyze([$cluster]);
        $this->assertSame([], $cluster->outlierMemberIds);
    }

    public function testFlagsClearOutlierMember(): void
    {
        $core = ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1];
        $cluster = $this->cluster([
            $this->blk($core),
            $this->blk($core),
            $this->blk($core),
            $this->blk(['z' => 1, 'y' => 1, 'x' => 1, 'w' => 1, 'v' => 1]),
        ]);
        (new CoherenceAnalyzer())->analyze([$cluster]);
        $this->assertSame([3], $cluster->outlierMemberIds);
    }

    public function testSkipsTinyClusters(): void
    {
        $cluster = $this->cluster([
            $this->blk(['a' => 1]),
            $this->blk(['z' => 1]),
        ]);
        (new CoherenceAnalyzer())->analyze([$cluster]);
        $this->assertSame([], $cluster->outlierMemberIds, 'no outliers below k=3');
    }

    public function testThresholdControlsSensitivity(): void
    {
        $cluster = $this->cluster([
            $this->blk(['a' => 1, 'b' => 1, 'c' => 1]),
            $this->blk(['a' => 1, 'b' => 1, 'c' => 1]),
            $this->blk(['a' => 1, 'b' => 1, 'd' => 1]),  // partial overlap
        ]);
        (new CoherenceAnalyzer(0.99))->analyze([$cluster]);
        $this->assertNotEmpty($cluster->outlierMemberIds, 'high threshold flags partial-overlap member');
    }

    /** @param list<Block> $members */
    private function cluster(array $members): Cluster
    {
        return new Cluster('TEST', $members, 1.0, false);
    }

    /** @param array<string,int> $bag */
    private function blk(array $bag): Block
    {
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode('<?php $x;');
        $b = new Block(
            file: 'x.php',
            range: new LineRange(1, 1),
            kind: 'method',
            namespace: null, class: null, name: null,
            ast: $stmts[0],
        );
        $b->ngramBag = $bag;
        return $b;
    }
}
