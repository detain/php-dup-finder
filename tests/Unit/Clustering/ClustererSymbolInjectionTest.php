<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Clusterer;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Index\SymbolIndex;
use Phpdup\Similarity\EditCostModel;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Phpdup\Util\LineRange;

/**
 * Item 3.4 (S4): the forced-pair lane inside candidate generation.
 *
 * The doctrine under test: forced pairs BYPASS THE PREFILTERS (df cap,
 * inverted index, F3 mass bound) and RIDE THE SCORING (a below-floor pair
 * is evidence, never an edge; an above-floor pair merges like any other).
 * Plus the OFF law: with no SymbolIndex the stream is byte-identical to
 * history — the frozen goldens lean on exactly this.
 */
final class ClustererSymbolInjectionTest extends TestCase
{
    private function block(string $id, string $file, ?string $name, array $bag, int $size = 6): Block
    {
        $b = new Block(
            file: $file,
            range: new LineRange(3, 9),
            kind: 'function',
            namespace: null,
            class: null,
            name: $name,
            ast: new Node\Stmt\Return_(new Node\Scalar\String_($id)),
        );
        $b->id = $id;
        $b->structuralHash = 'h_' . $id;
        $b->ngramBag = $bag;
        $b->size = $size;
        return $b;
    }

    /** @param list<Block> $blocks */
    private function indexWith(array $blocks): BlockIndex
    {
        $index = new BlockIndex();
        foreach ($blocks as $b) {
            $index->add($b);
        }
        return $index;
    }

    /**
     * @param list<Block> $blocks
     * @param array<string, mixed> $overrides
     * @return list<array{0:string,1:string}>
     */
    private function stream(array $blocks, bool $forced, array $overrides = []): array
    {
        $index = $this->indexWith($blocks);
        $symbols = null;
        if ($forced) {
            $symbols = new SymbolIndex();
            $symbols->build($index);
        }
        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(new EditCostModel('default'), false),
            similarityThreshold: 0.8,
            treeThreshold: 0.8,
            maxDocumentFrequency: $overrides['maxDocumentFrequency'] ?? 0.5,
            // tiny cap keeps every pair on the Jaccard-unverified lane:
            // no APTED call, so un-normalised fixture trees never load.
            maxTedSize: 1,
            candidateMassFilter: $overrides['candidateMassFilter'] ?? true,
            forcedSymbols: $symbols,
        );
        $rows = [];
        foreach ($clusterer->generateCandidatePairs($index) as [$lo, $hi]) {
            $rows[] = [$lo, $hi];
        }
        return $rows;
    }

    private function divergedPair(): array
    {
        // Disjoint bags: the inverted index shares NOTHING and the F3 bound
        // kills them — the natural stream never proposes this pair.
        return [
            $this->block('ffg1', '/p/tools/vps_create.php', 'create_vps', ['g1' => 3, 'g2' => 2]),
            $this->block('ffg2', '/q/tools/vps_create.php', 'create_vps', ['g3' => 3, 'g4' => 2]),
        ];
    }

    public function testOffStreamIsByteIdenticalToHistory(): void
    {
        $blocks = [
            $this->block('o1', '/a/f1.php', 'alpha', ['k' => 1, 'm' => 2, 'n' => 1]),
            $this->block('o2', '/b/f2.php', 'alpha', ['k' => 1, 'm' => 2, 'n' => 1]),
            $this->block('o3', '/c/f3.php', 'beta', ['z' => 5]),
        ];
        $index = $this->indexWith($blocks);

        $classic = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(new EditCostModel('default'), false),
            similarityThreshold: 0.8,
            treeThreshold: 0.8,
            maxDocumentFrequency: 0.5,
            maxTedSize: 1,
        );
        $rowsA = iterator_to_array($classic->generateCandidatePairs($index));

        // Same run with the parameter explicitly null — must be identical.
        $withNull = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(new EditCostModel('default'), false),
            similarityThreshold: 0.8,
            treeThreshold: 0.8,
            maxDocumentFrequency: 0.5,
            maxTedSize: 1,
            forcedSymbols: null,
        );
        $rowsB = iterator_to_array($withNull->generateCandidatePairs($index));

        self::assertSame($rowsA, $rowsB);
    }

    public function testForcedPairEntersStreamWherePrefiltersProposeNothing(): void
    {
        $blocks = $this->divergedPair();
        // df cap at near-zero: even shared grams could not carry the pair;
        // the bags here are disjoint anyway.
        self::assertSame([], $this->stream($blocks, forced: false, overrides: ['maxDocumentFrequency' => 0.01]),
            'the natural stream must genuinely propose nothing here');

        $on = $this->stream($blocks, forced: true, overrides: ['maxDocumentFrequency' => 0.01]);
        self::assertSame([['ffg1', 'ffg2']], $on, 'forced pair rides the stream despite every prefilter');
    }

    public function testForcedPairSurvivesTheMassFilterLane(): void
    {
        // F3 ON: the Σmin bound is 0 on the disjoint bags, so the natural
        // mass path would kill any touch — the forced slot enters anyway.
        $on = $this->stream($this->divergedPair(), forced: true, overrides: ['candidateMassFilter' => true]);
        self::assertCount(1, $on);
        self::assertSame(['ffg1', 'ffg2'], $on[0]);
    }

    public function testForcedAboveFloorMergesEdgeThroughNormalScoring(): void
    {
        // Same name, IDENTICAL bags => forced slot AND scoring acceptance.
        $blocks = [
            $this->block('mg1', '/p/a.php', 'twin', ['t1' => 4, 't2' => 3]),
            $this->block('mg2', '/q/b.php', 'twin', ['t1' => 4, 't2' => 3]),
        ];
        $index = $this->indexWith($blocks);
        $symbols = new SymbolIndex();
        $symbols->build($index);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(new EditCostModel('default'), false),
            similarityThreshold: 0.8,
            treeThreshold: 0.8,
            maxDocumentFrequency: 0.01, // natural stream stays empty: only the forced lane can feed this pair
            maxTedSize: 1,             // jaccard-unverified lane, no APTED
            forcedSymbols: $symbols,
        );
        $clusters = $clusterer->cluster($index);
        self::assertCount(1, $clusters);
        $ids = array_map(static fn(Block $m): string => $m->id, $clusters[0]->members);
        sort($ids);
        self::assertSame(['mg1', 'mg2'], $ids);
        self::assertSame(1.0, $clusters[0]->similarity);
    }

    public function testForcedBelowFloorStaysEvidenceNotEdge(): void
    {
        $index = $this->indexWith($this->divergedPair());
        $symbols = new SymbolIndex();
        $symbols->build($index);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(new EditCostModel('default'), false),
            similarityThreshold: 0.8,
            treeThreshold: 0.8,
            maxDocumentFrequency: 0.01,
            maxTedSize: 1,
            forcedSymbols: $symbols,
        );
        // The pair IS compared (one candidate) but the score says no — zero
        // clusters is the honest outcome, never a forced merge.
        $clusters = $clusterer->cluster($index);
        self::assertSame([], $clusters);
    }

    public function testForcedDedupsAgainstTheNaturalStream(): void
    {
        $blocks = [
            $this->block('dd1', '/p/a.php', 'shared', ['u1' => 5, 'u2' => 5]),
            $this->block('dd2', '/q/b.php', 'shared', ['u1' => 5, 'u2' => 5]),
        ];
        $off = $this->stream($blocks, forced: false);
        $on = $this->stream($blocks, forced: true);
        self::assertCount(1, $off, 'the natural lane already proposes this pair');
        self::assertCount(1, $on, 'the forced slot consumes it once — never twice');
        self::assertSame($off, $on);
    }

    public function testBudgetCountsForcedPairs(): void
    {
        $index = $this->indexWith($this->divergedPair());
        $symbols = new SymbolIndex();
        $symbols->build($index);
        self::assertSame(1, $symbols->countForcedPairs());

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(new EditCostModel('default'), false),
            similarityThreshold: 0.8,
            treeThreshold: 0.8,
            maxDocumentFrequency: 0.5,
            maxTedSize: 1,
            forcedSymbols: $symbols,
        );
        $seen = 0;
        try {
            foreach ($clusterer->generateCandidatePairs($index, maxCandidatePairs: 1) as $pair) {
                $seen++;
                if ($seen >= 1) {
                    // force the generator past the budget with a second touch
                    continue;
                }
            }
            // drain fully: the diverged pair is forced (1) and nothing else
            // is natural here, so budget 1 is NOT exceeded by this corpus —
            // use a second forced pair to trip it instead:
            self::assertSame(1, $seen);
        } catch (\Phpdup\Clustering\CandidateBudgetException $e) {
            self::fail('unexpected budget trip on a one-pair corpus');
        }

        // Now a corpus where forced pairs alone exceed the budget:
        $blocks = [
            $this->block('b1', '/p1/x.php', 'one', ['p' => 1]),
            $this->block('b2', '/p2/x.php', 'one', ['q' => 1]),
            $this->block('b3', '/p3/x.php', 'one', ['r' => 1]),
        ];
        $index2 = $this->indexWith($blocks);
        $symbols2 = new SymbolIndex();
        $symbols2->build($index2);
        self::assertSame(3, $symbols2->countForcedPairs());
        $clusterer2 = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(new EditCostModel('default'), false),
            similarityThreshold: 0.8,
            treeThreshold: 0.8,
            maxDocumentFrequency: 0.5,
            maxTedSize: 1,
            forcedSymbols: $symbols2,
        );
        $this->expectException(\Phpdup\Clustering\CandidateBudgetException::class);
        iterator_to_array($clusterer2->generateCandidatePairs($index2, maxCandidatePairs: 2));
    }

    public function testExactOnlyNeverSeesTheForcedStream(): void
    {
        $blocks = [
            $this->block('ex1', '/p/a.php', 'twin', ['w' => 1]),
            $this->block('ex2', '/q/a.php', 'twin', ['w' => 1]),
            $this->block('ex3', '/r/b.php', 'twin', ['x' => 1]),
        ];
        $index = $this->indexWith($blocks);
        $symbols = new SymbolIndex();
        $symbols->build($index);

        $exactOnly = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(new EditCostModel('default'), false),
            similarityThreshold: 0.8,
            treeThreshold: 0.8,
            maxDocumentFrequency: 0.5,
            exactOnly: true,
            forcedSymbols: $symbols,
        );
        $clusters = $exactOnly->cluster($index);
        // Only the IDENTICAL-hash bucket (ex1 vs ex3? bags differ; 'w' ids
        // differ => structuralHash differs for all three here) — this fixture
        // has equal bags for ex1/ex3 but different structuralHash, so the
        // honest exact-only outcome is ZERO clusters: forced pairs ride
        // phase 2, which exactOnly skips.
        self::assertSame([], $clusters, 'exactOnly mode is untouched by the symbol lane');
    }
}
