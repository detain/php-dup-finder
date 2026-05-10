<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Index\BloomCandidateIndex;
use Phpdup\Util\LineRange;

final class BloomCandidateIndexTest extends TestCase
{
    public function testReturnsCandidatesSharingNgrams(): void
    {
        // Use realistic-sized bags so the bit-overlap test isn't dominated
        // by the tiny-input false-positive rate.
        $shared = array_fill_keys(array_map(fn($i) => "shared-$i", range(1, 30)), 1);
        $a = $this->blk('A', $shared + array_fill_keys(['x', 'y', 'z', 'q'], 1));
        $b = $this->blk('B', $shared + array_fill_keys(['x', 'y', 'z', 'r'], 1));
        $c = $this->blk('C', array_fill_keys(array_map(fn($i) => "far-$i", range(1, 30)), 1));

        $idx = new BlockIndex();
        $idx->add($a); $idx->add($b); $idx->add($c);
        $bloom = new BloomCandidateIndex();
        $bloom->build($idx);

        $cands = $bloom->candidatesFor($a);
        $this->assertContains('B', $cands);
        $this->assertNotContains('C', $cands);
        $this->assertNotContains('A', $cands, 'never includes self');
    }

    public function testEmptyForUnknownBlock(): void
    {
        $idx   = new BlockIndex();
        $bloom = new BloomCandidateIndex();
        $bloom->build($idx);
        $stranger = $this->blk('X', ['z' => 1]);
        $this->assertSame([], $bloom->candidatesFor($stranger));
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
