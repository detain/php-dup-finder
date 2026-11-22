<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Clusterer;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Index\BlockIndex;
use Phpdup\Ml\PairScorer;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;

/**
 * Verifies that the option-6 ML pair-tier fallback in
 * {@see Clusterer} produces edges when the model returns a high
 * enough similarity for a pair the AST + IR tiers reject, and
 * gracefully no-ops when the model is unavailable.
 *
 * The scorer is mocked via an anonymous subclass of {@see MlPairClient}
 * — we don't run a real HTTP server in unit tests; the integration
 * contract is exercised by {@see \Phpdup\Tests\Unit\Ml\MlPairClientTest}.
 */
final class MlPairTierClustererTest extends TestCase
{
    public function testMlTierProducesEdgeWhenAstAndIrTiersReject(): void
    {
        $a = $this->buildBlock('<?php function f($x) { return $x + 1; }');
        $b = $this->buildBlock('<?php function f($id) { User::destroy($id); }');

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);

        $client = $this->fakeClient(returnSimilarity: 0.95);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.99,
            treeThreshold: 0.99,
            maxDocumentFrequency: 1.0,
            optionalBlocksEnabled: false,
            irScoring: false,
            mlPairClient: $client,
            mlPairThreshold: 0.80,
        );

        $clusters = $clusterer->cluster($index);
        $this->assertCount(1, $clusters,
            'a sufficiently high model-returned similarity must produce an edge');
        $this->assertCount(2, $clusters[0]->members);
    }

    public function testMlTierNoEdgeWhenModelScoresBelowThreshold(): void
    {
        $a = $this->buildBlock('<?php function f($x) { return $x + 1; }');
        $b = $this->buildBlock('<?php function f($id) { User::destroy($id); }');

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);

        $client = $this->fakeClient(returnSimilarity: 0.50);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.99,
            treeThreshold: 0.99,
            maxDocumentFrequency: 1.0,
            optionalBlocksEnabled: false,
            mlPairClient: $client,
            mlPairThreshold: 0.80,
        );

        $this->assertSame([], $clusterer->cluster($index));
    }

    public function testMlTierFailsGracefullyWhenClientReturnsNull(): void
    {
        $a = $this->buildBlock('<?php function f($x) { return $x + 1; }');
        $b = $this->buildBlock('<?php function f($id) { User::destroy($id); }');

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);

        $client = $this->fakeClient(returnSimilarity: null);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.99,
            treeThreshold: 0.99,
            maxDocumentFrequency: 1.0,
            optionalBlocksEnabled: false,
            mlPairClient: $client,
            mlPairThreshold: 0.80,
        );

        $this->assertSame([], $clusterer->cluster($index),
            'a null score (transport error) must leave the cluster set unchanged');
    }

    public function testNoMlClientLeavesPipelineUnchanged(): void
    {
        // Sanity check: without an MlPairClient, behaviour is the
        // baseline AST scoring tier (here both blocks share the same
        // canonical hash so they cluster trivially).
        $a = $this->buildBlock('<?php function f($x) { return $x + 1; }');
        $b = $this->buildBlock('<?php function g($y) { return $y + 1; }');

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
        );
        $this->assertCount(1, $clusterer->cluster($index));
    }

    /**
     * Build an in-memory PairScorer that returns a fixed
     * `{similarity, confidence}` payload (or null) without going
     * over the network.
     */
    private function fakeClient(?float $returnSimilarity): PairScorer
    {
        return new class($returnSimilarity) implements PairScorer {
            public function __construct(private readonly ?float $sim) {}
            public function score(Block $a, Block $b): ?array
            {
                if ($this->sim === null) {
                    return null;
                }
                return ['similarity' => $this->sim, 'confidence' => 1.0];
            }
        };
    }

    private function buildBlock(string $code): Block
    {
        $stmts = (new AstParser())->parseCode($code);
        $blocks = (new BlockExtractor(minSize: 1))->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        $block = $blocks[0];
        (new Normalizer(mode: 'aggressive'))->normalize($block);
        $block->structuralHash = (new SubtreeHasher())->hash($block->canonical);
        $block->ngramBag = (new NgramFingerprint(3))->fingerprint($block->canonical);
        $block->id = $block->structuralHash . '_' . spl_object_id($block);
        return $block;
    }

    // -----------------------------------------------------------------
    // engine-S15 / php-dup-finder-engine-056 item 2 — the ML lens is a
    // rescue for pairs the TREE refuted, not only for pairs the bag
    // gate rejected: a Jaccard-pass + TED-fail pair used to die at the
    // `continue` before the model was ever consulted.
    // -----------------------------------------------------------------

    public function testMlArmRescuesATedRefutedPairPreviouslyDead(): void
    {
        [$a, $b] = $this->tedRefutedPair();

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.8,   // bag gate PASSES (forced-equal bags)
            treeThreshold: 0.85,        // APTED refutes — the TED-refuted lane
            maxDocumentFrequency: 1.0,
            optionalBlocksEnabled: false,
            irScoring: false,
            mlPairClient: $this->fakeClient(returnSimilarity: 0.95),
            mlPairThreshold: 0.80,
        );

        $clusters = $clusterer->cluster($index);
        $this->assertCount(1, $clusters, 'the ML arm must evaluate a TED-refuted pair (engine-056 acceptance)');
        $this->assertSame('ml', $clusters[0]->pairs[0]['matchTier'],
            'first-wins: Jaccard never emitted the edge (TED refuted it), so the ML arm stamps its own tier');
    }

    public function testSameTedRefutedPairStaysDeadWithoutTheMlLens(): void
    {
        // Control: same pair, no model — nothing survives (containment
        // excluded by the scope ruling, IR off), as the old `continue`
        // produced.
        [$a, $b] = $this->tedRefutedPair();

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.8,
            treeThreshold: 0.85,
            maxDocumentFrequency: 1.0,
            optionalBlocksEnabled: false,
            irScoring: false,
        );

        $this->assertSame([], $clusterer->cluster($index));
    }

    /**
     * Straight-line vs nested-control-flow pair with forced-equal n-gram
     * bags: Jaccard claims it at 1.0, real APTED refutes it. Builder
     * sanity pinned so normaliser drift cannot move the pair out of the
     * TED-refuted lane unnoticed.
     *
     * @return array{0: Block, 1: Block}
     */
    private function tedRefutedPair(): array
    {
        $codeA = '<?php function probe_A($p) { $a = strlen($p); $b = $a + 1; $c = $b + 2; $d = $c * 3; return [$a, $b, $c, $d]; }';
        $codeB = '<?php function probe_B($p) { if ($p) { $a = strlen($p); $b = $a + 1; } else { $a = 0; $b = 0; } '
            . 'for ($i = 0; $i < 2; $i++) { $c = $b + $i; } $d = $c * 3; return [$a, $b, $c, $d]; }';

        $blocks = [];
        foreach ([['ML-A', $codeA], ['ML-B', $codeB]] as [$id, $code]) {
            $stmts = (new AstParser())->parseCode($code);
            $this->assertNotNull($stmts);
            $block = (new BlockExtractor(minSize: 1, maxSize: 100000))->extract('test.php', $stmts)[0];
            (new Normalizer(mode: 'aggressive'))->normalize($block);
            $block->structuralHash = (new SubtreeHasher())->hash($block->canonical);
            $block->id       = $id;
            $block->ngramBag = ['g_strlen' => 2, 'g_add' => 3, 'g_mul' => 1, 'g_ret' => 2];
            $blocks[] = $block;
        }

        $jac    = (new JaccardSimilarity())->similarity($blocks[0]->ngramBag, $blocks[1]->ngramBag);
        $tedSim = (new TreeEditDistance())->similarity($blocks[0]->canonical, $blocks[1]->canonical, 0.85);
        $this->assertNotSame($blocks[0]->structuralHash, $blocks[1]->structuralHash,
            'builder sanity: divergent trees must not collapse to one structural hash');
        $this->assertGreaterThanOrEqual(0.8, $jac, 'builder sanity: pair reaches the Jaccard lane');
        $this->assertLessThan(0.85, $tedSim, 'builder sanity: APTED must refute the pair (TED-refuted lane)');

        return $blocks;
    }
}
