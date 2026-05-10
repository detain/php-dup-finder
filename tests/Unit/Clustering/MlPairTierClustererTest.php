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
}
