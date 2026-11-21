<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\CandidateBudgetException;
use Phpdup\Clustering\Clusterer;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Phpdup\Util\LineRange;

/**
 * Generation-loop budget guard (improve_logic.md item 0.5, F3 leg).
 *
 * A clone family chains through rare-gram postings (the 167-member
 * sliding-window chain texture of filing 06 Demo A scaled to a storm), so
 * candidate-pair output grows quadratically in family size and — measured
 * at 2,400-3,400 pairs/block on the /home-my corpora — blew up to 80 GB
 * before scoring ever started. {@see Clusterer::generateCandidatePairs()}
 * now halts loudly at MAX_CANDIDATE_PAIRS instead of OOM-ing.
 *
 * Proven here:
 *   (a) a pathological chain/storm is capped — the guard fires at exactly
 *       the budget and the pre-halt stream is the exact prefix of the
 *       unguarded enumeration (never reordered, skipped, or duplicated);
 *   (b) normal corpora complete untouched — the armed-guard stream is
 *       byte-identical to the UNLIMITED (guard-off) stream.
 */
final class GenerationBudgetTest extends TestCase
{
    private function clusterer(): Clusterer
    {
        // Candidate generation consults only maxDocumentFrequency; the
        // scoring thresholds are inert here (same as ClustererGeneratorTest).
        return new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
        );
    }

    private function makeBlock(string $id, array $ngrams): Block
    {
        $block = new Block(
            file: 'synthetic.php',
            range: new LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: null,
            ast: new \PhpParser\Node\Stmt\Return_(new \PhpParser\Node\Scalar\String_($id)),
        );
        $block->id = $id;
        $block->structuralHash = 'hash_' . $id;
        $block->ngramBag = $ngrams;

        return $block;
    }

    /**
     * Sliding-window chain: gram g_j links blocks j…j+w-1, so member i is a
     * candidate of every member within w-1 steps — the 167-chain texture.
     * Unique unordered pairs: (w-1)·N − w·(w-1)/2.
     */
    private function chainIndex(int $n, int $w): BlockIndex
    {
        $index = new BlockIndex();
        for ($i = 0; $i < $n; $i++) {
            $bag = ['unique_' . $i => 1];
            for ($j = max(0, $i - $w + 1); $j <= min($i, $n - $w); $j++) {
                $bag['bridge_' . $j] = 1;
            }
            $index->add($this->makeBlock(sprintf('C%04d', $i), $bag));
        }
        return $index;
    }

    /** k mutually-similar blocks sharing one rare gram; pairs = k(k-1)/2. */
    private function cliqueIndex(int $k): BlockIndex
    {
        $index = new BlockIndex();
        for ($i = 0; $i < $k; $i++) {
            $index->add($this->makeBlock(sprintf('K%04d', $i), ['family_gram' => 1, 'unique_' . $i => 1]));
        }
        return $index;
    }

    /** n unrelated blocks; seeds zero candidates. */
    private function strangerIndex(int $n): BlockIndex
    {
        $index = new BlockIndex();
        for ($i = 0; $i < $n; $i++) {
            $index->add($this->makeBlock('S' . $i, ['only_' . $i => 1]));
        }
        return $index;
    }

    /** @return list<array{0:string,1:string}> */
    private function collect(Clusterer $clusterer, BlockIndex $index, ?int $budget): array
    {
        $pairs = [];
        foreach ($clusterer->generateCandidatePairs($index, null, $budget) as $pair) {
            $pairs[] = $pair;
        }
        return $pairs;
    }

    /** @return array{0:list<array{0:string,1:string}>,1:CandidateBudgetException} */
    private function collectUntilHalt(Clusterer $clusterer, BlockIndex $index, int $budget): array
    {
        $pairs = [];
        try {
            foreach ($clusterer->generateCandidatePairs($index, null, $budget) as $pair) {
                $pairs[] = $pair;
            }
        } catch (CandidateBudgetException $e) {
            return [$pairs, $e];
        }
        $this->fail(sprintf('expected CandidateBudgetException at budget %d', $budget));
    }

    private function fingerprint(array $pairs): string
    {
        return hash('sha256', (string)json_encode($pairs));
    }

    /** Analytic pair count of chainIndex(): gaps 1..w-1 over n blocks. */
    private function chainPairCount(int $n, int $w): int
    {
        return ($w - 1) * $n - $w * ($w - 1) / 2;
    }

    public function testChainPairMathematicsMatchesAnalyticCount(): void
    {
        // Foundation for every budget assertion below: the unguarded stream
        // is deterministic and equals the closed-form family pair mass.
        $pairs = $this->collect($this->clusterer(), $this->chainIndex(25, 3), Clusterer::UNLIMITED_CANDIDATE_PAIRS);

        $this->assertCount($this->chainPairCount(25, 3), $pairs);
        $this->assertSame($pairs, $this->collect($this->clusterer(), $this->chainIndex(25, 3), Clusterer::UNLIMITED_CANDIDATE_PAIRS),
            'enumeration must be deterministic across runs');
    }

    public function testArmedGuardHaltsPathologicalStormExactlyAtBudget(): void
    {
        // 30-member clique = 435 storm pairs; a budget of 100 must deliver
        // exactly 100 and then fail loud — not keep enumerating 435.
        $index = $this->cliqueIndex(30);
        $total = count($this->collect($this->clusterer(), $index, Clusterer::UNLIMITED_CANDIDATE_PAIRS));
        $this->assertSame(435, $total, 'the unguarded storm mass is what the guard exists to bound');

        [$stream, $exception] = $this->collectUntilHalt($this->clusterer(), $index, 100);

        $this->assertCount(100, $stream, 'the guard must yield exactly `budget` pairs before halting');

        $message = $exception->getMessage();
        $this->assertStringContainsString('100', $message, 'the message must name the breached budget');
        $this->assertStringContainsString('--max-df', $message, 'the message must cite the real knob that prunes the pair set');
        $this->assertStringContainsString('of 30 blocks', $message, 'the message must be honest about the corpus scale');
    }

    public function testHaltedStreamIsExactPrefixOfUnguardedEnumeration(): void
    {
        // The guard must not reorder, skip, or duplicate anything below the
        // cap: byte-identical prefix ⇒ any completed run equals master.
        $index = $this->chainIndex(60, 4);
        $unguarded = $this->collect($this->clusterer(), $index, Clusterer::UNLIMITED_CANDIDATE_PAIRS);
        $budget = intdiv(count($unguarded), 2);

        [$stream, $exception] = $this->collectUntilHalt($this->clusterer(), $index, $budget);

        $this->assertSame(array_slice($unguarded, 0, $budget), $stream,
            'pre-halt stream must equal the first `budget` pairs of the unguarded enumeration');
        $this->assertSame($this->fingerprint(array_slice($unguarded, 0, $budget)), $this->fingerprint($stream));
        $this->assertInstanceOf(CandidateBudgetException::class, $exception);
    }

    public function testNormalCorporaAreByteIdenticalGuardArmedVersusGuardOff(): void
    {
        // (b) of the lane mandate: on every corpus that completes today
        // (far below the 60M ceiling) the armed guard is a no-op.
        $corpora = [
            'small chain'   => $this->chainIndex(25, 3),
            'wide chain'    => $this->chainIndex(120, 6),
            'clique family' => $this->cliqueIndex(9),
            'strangers'     => $this->strangerIndex(12),
        ];

        foreach ($corpora as $name => $index) {
            $armed = $this->collect($this->clusterer(), $index, null);
            $off   = $this->collect($this->clusterer(), $index, Clusterer::UNLIMITED_CANDIDATE_PAIRS);

            $this->assertSame($off, $armed, "guard-armed output must be pair-for-pair identical to guard-off: $name");
            $this->assertSame($this->fingerprint($off), $this->fingerprint($armed), "byte identity: $name");
        }
    }

    public function testBudgetAtExactTotalCompletesOneShortHalts(): void
    {
        $index = $this->chainIndex(20, 3);
        $total = $this->chainPairCount(20, 3);

        $exact = $this->collect($this->clusterer(), $index, $total);
        $this->assertCount($total, $exact, 'a budget equal to the corpus must complete silently');

        [$shortStream, $exception] = $this->collectUntilHalt($this->clusterer(), $index, $total - 1);
        $this->assertCount($total - 1, $shortStream);
        $this->assertInstanceOf(CandidateBudgetException::class, $exception);
    }

    public function testPathologicalChainCannotExplodePairOutput(): void
    {
        // The lane's headline property: a 400-member, window-8 chain storm
        // (2,772 pairs) completes trivially under the armed default, and
        // under any tighter budget it halts AT the budget — bounded, always.
        $index = $this->chainIndex(400, 8);
        $total = $this->chainPairCount(400, 8);
        $this->assertSame(2772, $total);

        $armed = $this->collect($this->clusterer(), $index, null);
        $this->assertCount($total, $armed, 'the production ceiling never fires on a 2.7k-pair storm');

        [$stream, $exception] = $this->collectUntilHalt($this->clusterer(), $index, 1386);
        $this->assertCount(1386, $stream);
        $this->assertInstanceOf(CandidateBudgetException::class, $exception);
    }

    /** @dataProvider invalidBudgets */
    public function testNonPositiveBudgetIsRejected(int $budget): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('>= 1');
        $this->collect($this->clusterer(), $this->cliqueIndex(2), $budget);
    }

    /** @return array<string, array{0:int}> */
    public static function invalidBudgets(): array
    {
        return ['zero' => [0], 'negative' => [-5]];
    }

    public function testProductionCeilingIsPinnedWhereMeasuredCorporaStayFarBelow(): void
    {
        // Guard-rail on the constant itself: the largest corpus ever
        // completed (phlix, 18.8M pairs) must stay well under the budget,
        // and the observed death zone (~320M materialized at 80 GB) well above it.
        $this->assertSame(60_000_000, Clusterer::MAX_CANDIDATE_PAIRS);
        $this->assertGreaterThan(3 * 18_800_000, Clusterer::MAX_CANDIDATE_PAIRS,
            'completed corpora must sit under a third of the budget');
        $this->assertLessThan(intdiv(320_000_000, 5), Clusterer::MAX_CANDIDATE_PAIRS,
            'the budget must trip long before the historical 80-GB death zone');
    }
}
