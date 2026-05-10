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
use Phpdup\Ir\IrLifter;
use Phpdup\Ir\IrPrinter;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;

/**
 * Verifies that the option-5 IR-tier fallback in {@see Clusterer}
 * produces edges for pairs the AST-level tiers reject.
 */
final class IrTierClustererTest extends TestCase
{
    public function testIrTierProducesEdgeWhenAstTiersReject(): void
    {
        // Two functions that lift to identical IR (a single
        // DbReadIr("user")) but whose AST n-gram bags overlap very
        // little because the surface call shapes differ.
        $eloquent = $this->buildBlock(
            '<?php function f($id) { return User::find($id); }',
            irMode: true,
        );
        $doctrine = $this->buildBlock(
            '<?php function f($em, $id) { return $em->find(User::class, $id); }',
            irMode: true,
        );

        $index = new BlockIndex();
        $index->add($eloquent);
        $index->add($doctrine);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.99, // force AST Jaccard reject
            treeThreshold: 0.99,
            maxDocumentFrequency: 1.0, // accept every n-gram as candidate seed
            optionalBlocksEnabled: false, // skip containment fallback
            irScoring: true,
            irThreshold: 0.85,
        );

        $clusters = $clusterer->cluster($index);
        // The two blocks must end up in the same cluster solely via the IR tier.
        $this->assertCount(1, $clusters);
        $this->assertCount(2, $clusters[0]->members);
    }

    public function testIrTierIsSkippedWhenIrBagIsNull(): void
    {
        // No IR bag = silent skip; the cluster set is empty when AST
        // tiers also reject (which they do — different shapes).
        $a = $this->buildBlock('<?php function f($id) { return User::find($id); }', irMode: false);
        $b = $this->buildBlock(
            '<?php function f($em, $id) { return $em->find(User::class, $id); }',
            irMode: false,
        );

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.99,
            treeThreshold: 0.99,
            maxDocumentFrequency: 1.0,
            optionalBlocksEnabled: false,
            irScoring: true, // tier enabled, but irBag null on both
            irThreshold: 0.85,
        );

        $this->assertSame([], $clusterer->cluster($index));
    }

    public function testIrTierBelowThresholdProducesNoEdge(): void
    {
        // Two operationally distinct DB ops — a read and a delete —
        // produce different IR shapes and should NOT cluster even
        // with a permissive IR threshold below typical separation.
        $read   = $this->buildBlock('<?php function f($id) { return User::find($id); }', irMode: true);
        $delete = $this->buildBlock('<?php function f($id) { User::destroy($id); }', irMode: true);

        $index = new BlockIndex();
        $index->add($read);
        $index->add($delete);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.99,
            treeThreshold: 0.99,
            maxDocumentFrequency: 1.0,
            optionalBlocksEnabled: false,
            irScoring: true,
            irThreshold: 0.85,
        );

        $this->assertSame([], $clusterer->cluster($index));
    }

    public function testDefaultModeStillWorksWithoutIrBag(): void
    {
        // Sanity check: the IR-tier knob is off by default and the
        // legacy AST path keeps clustering identical-hash blocks.
        $a = $this->buildBlock('<?php function f($x) { return $x + 1; }', irMode: false);
        $b = $this->buildBlock('<?php function g($y) { return $y + 1; }', irMode: false);

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
        );
        $clusters = $clusterer->cluster($index);
        $this->assertCount(1, $clusters);
    }

    private function buildBlock(string $code, bool $irMode): Block
    {
        $stmts = (new AstParser())->parseCode($code);
        $blocks = (new BlockExtractor(minSize: 1))->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        $block = $blocks[0];
        (new Normalizer(mode: 'aggressive'))->normalize($block);
        $block->structuralHash = (new SubtreeHasher())->hash($block->canonical);
        $block->ngramBag = (new NgramFingerprint(3))->fingerprint($block->canonical);
        $block->id = $block->structuralHash . '_' . spl_object_id($block);

        if ($irMode && $block->ast !== null) {
            $ir = (new IrLifter())->lift($block->ast);
            if ($ir !== null) {
                $tokens = (new IrPrinter())->tokens($ir);
                $bag = [];
                foreach ($tokens as $t) {
                    $bag[$t] = ($bag[$t] ?? 0) + 1;
                }
                $block->irBag = $bag;
            }
        }
        return $block;
    }
}
