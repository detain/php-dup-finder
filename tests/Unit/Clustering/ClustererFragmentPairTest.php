<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Clusterer;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Index\BlockIndex;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Phpdup\Util\LineRange;

/**
 * Item 2.1 — the candidate-stream discipline for fragment runs.
 * generateCandidatePairs is the ONE enumeration stream (E1 law): the
 * fragment guard may only REMOVE pairs from it, and it must remove
 * exactly the two artifact classes — same-file runs whose line ranges
 * overlap (nested windows of one statement list) and cross-file pairs
 * whose LIST-RELATIVE KEYS show one run containing the other (the
 * §5c nested-family chain) — while every genuine twin pair survives.
 * Corpora without fragments must enumerate identically (the guard
 * prepass short-circuits on the first fragment).
 */
final class ClustererFragmentPairTest extends TestCase
{
    private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; // 40 hex
    private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const SHA_C = 'cccccccccccccccccccccccccccccccccccccccc';

    private int $seq = 0;

    private function mkBlock(string $file, int $start, int $end, string $kind, string $key, array $bag, string $hash): Block
    {
        static $ast = null;
        $ast ??= new Node\Stmt\Return_(new Node\Scalar\String_('same'));

        $b = new Block($file, new LineRange($start, $end), $kind, null, null, null, $ast);
        $b->id = sprintf('%04d', ++$this->seq);
        $b->structuralHash = $hash;
        $b->ngramBag = $bag;
        $b->canonical = $ast;
        $b->fragmentKey = $key;
        return $b;
    }

    private function mkFrag(string $file, int $start, int $end, string $key): Block
    {
        // Identical bags for the twin classes so the inverted index
        // proposes every cross-run pair; the guard is what filters.
        return $this->mkBlock($file, $start, $end, BlockExtractor::FRAGMENT_KIND, $key, ['t1' => 5, 't2' => 4, 't3' => 3], $key);
    }

    /** @return list<array{0:string,1:string}> */
    private function collect(BlockIndex $index): array
    {
        $clusterer = new Clusterer(new JaccardSimilarity(), new TreeEditDistance(), 0.80, 0.85, 0.9);
        $pairs = [];
        foreach ($clusterer->generateCandidatePairs($index) as $pair) {
            $pairs[] = $pair;
        }
        return $pairs;
    }

    /** @param list<array{0:string,1:string}> $pairs */
    private function hasPair(array $pairs, string $a, string $b): bool
    {
        foreach ($pairs as $p) {
            if (($p[0] === $a && $p[1] === $b) || ($p[0] === $b && $p[1] === $a)) {
                return true;
            }
        }
        return false;
    }

    public function testDefaultCorpusStreamHasNoFragmentArm(): void
    {
        $index = new BlockIndex();
        $a = $this->mkBlock('x.php', 1, 9, 'method', '', ['t1' => 5, 't2' => 4], 'hash-a');
        $b = $this->mkBlock('y.php', 1, 9, 'method', '', ['t1' => 5, 't2' => 4], 'hash-b');
        $index->add($a);
        $index->add($b);
        $this->assertTrue($this->hasPair($this->collect($index), $a->id, $b->id), 'ordinary pairs enumerate as before');
    }

    public function testSameFileOverlappingFragmentsAreNotPaired(): void
    {
        $index = new BlockIndex();
        $short = $this->mkFrag('x.php', 12, 26, self::SHA_A);
        $long  = $this->mkFrag('x.php', 12, 27, self::SHA_A . '~' . self::SHA_C);
        $index->add($short);
        $index->add($long);
        $this->assertFalse($this->hasPair($this->collect($index), $short->id, $long->id),
            'nested windows of one list are structure, never duplication evidence');
    }

    public function testSameFileDisjointFragmentsArePaired(): void
    {
        $index = new BlockIndex();
        $p = $this->mkFrag('x.php', 12, 20, self::SHA_A);
        $q = $this->mkFrag('x.php', 40, 48, self::SHA_B);
        $index->add($p);
        $index->add($q);
        $this->assertTrue($this->hasPair($this->collect($index), $p->id, $q->id),
            'non-overlapping same-file runs remain legitimate in-file candidates');
    }

    public function testCrossFileNestedKeyFragmentsAreNotPaired(): void
    {
        $index = new BlockIndex();
        $base  = $this->mkFrag('x.php', 12, 26, self::SHA_A);
        $chain = $this->mkFrag('y.php', 12, 27, self::SHA_A . '~' . self::SHA_C);
        $index->add($base);
        $index->add($chain);
        $this->assertFalse($this->hasPair($this->collect($index), $base->id, $chain->id),
            'the falling-support chain must stay apart — one diluting near-edge would weld it');
    }

    public function testCrossFileExactTwinFragmentsRemainPaired(): void
    {
        $index = new BlockIndex();
        $x = $this->mkFrag('x.php', 12, 26, self::SHA_A);
        $y = $this->mkFrag('y.php', 100, 114, self::SHA_A);
        $index->add($x);
        $index->add($y);
        $this->assertTrue($this->hasPair($this->collect($index), $x->id, $y->id),
            'the stitched finding — identical runs at different offsets — is never removed');
    }
}
