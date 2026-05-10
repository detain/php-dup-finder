<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Index\LshIndex;
use Phpdup\Util\LineRange;

final class LshIndexTest extends TestCase
{
    public function testCandidatesShareBucket(): void
    {
        $shared = array_fill_keys(array_map(fn($i) => "shared-$i", range(1, 30)), 1);
        $a = $this->blk('A', $shared + array_fill_keys(['extraA1', 'extraA2'], 1));
        $b = $this->blk('B', $shared + array_fill_keys(['extraB1', 'extraB2'], 1));
        $c = $this->blk('C', array_fill_keys(array_map(fn($i) => "far-$i", range(1, 30)), 1));

        $idx = new BlockIndex();
        $idx->add($a); $idx->add($b); $idx->add($c);
        $lsh = new LshIndex();
        $lsh->build($idx);

        $cands = $lsh->candidatesFor($a);
        $this->assertContains('B', $cands);
        $this->assertNotContains('C', $cands);
        $this->assertNotContains('A', $cands, 'never includes self');
    }

    public function testEmptyForUnknownBlock(): void
    {
        $idx = new BlockIndex();
        $lsh = new LshIndex();
        $lsh->build($idx);
        $stranger = $this->blk('X', ['z' => 1]);
        $this->assertSame([], $lsh->candidatesFor($stranger));
    }

    public function testEstimateJaccardZeroForUnknown(): void
    {
        $idx = new BlockIndex();
        $lsh = new LshIndex();
        $lsh->build($idx);
        $this->assertSame(0.0, $lsh->estimateJaccard('A', 'B'));
    }

    /** @param array<string,int> $bag */
    private function blk(string $id, array $bag): Block
    {
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode('<?php $a;');
        $b = new Block(
            file: 'x.php',
            range: new LineRange(1, 1),
            kind: 'method',
            namespace: null, class: null, name: null,
            ast: $stmts[0],
        );
        $b->id = $id;
        $b->ngramBag = $bag;
        return $b;
    }
}
