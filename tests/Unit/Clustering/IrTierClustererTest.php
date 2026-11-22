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

    // -----------------------------------------------------------------
    // engine-S15 / php-dup-finder-engine-056 item 2 — the IR lens is a
    // rescue for pairs the TREE refuted, not only for pairs the bag
    // gate rejected. Old law: a Jaccard-pass + TED-fail pair hit
    // `continue` and died before every later arm. New law: it falls
    // through to IR (and ML), so `--scorer=ir` can mint an edge the
    // old cascade structurally could not.
    // -----------------------------------------------------------------

    public function testIrArmRescuesATedRefutedPairPreviouslyDead(): void
    {
        [$a, $b] = $this->tedRefutedPair();

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.8,   // the pair PASSES the bag gate (forced-equal bags)
            treeThreshold: 0.85,        // ...and FAILS APTED (divergent trees) — the refuted lane
            maxDocumentFrequency: 1.0,
            optionalBlocksEnabled: false,
            irScoring: true,
            irThreshold: 0.85,
        );

        $clusters = $clusterer->cluster($index);
        $this->assertCount(1, $clusters, 'the IR arm must evaluate a TED-refuted pair (engine-056 acceptance)');
        $this->assertCount(2, $clusters[0]->members);
        $this->assertSame('ir', $clusters[0]->pairs[0]['matchTier'],
            'first-wins: Jaccard never emitted the edge (TED refuted it), so the IR arm stamps its own tier');
    }

    public function testSameTedRefutedPairStaysDeadWithoutTheIrLens(): void
    {
        // Control that keeps the rescue honest: same pair, IR off — no
        // arm survives (containment excluded by the scope ruling, ML
        // absent) exactly as the old `continue` produced.
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
     * Straight-line vs nested-control-flow pair: structurally divergent
     * (real APTED < treeThreshold) with deliberately forced-equal n-gram
     * and IR bags so the bag gate claims it at 1.0 and only the tree
     * lens refutes it. Builder sanity (jac pass, TED fail) is pinned at
     * the call site so a future normaliser drift cannot silently move
     * the pair out of the TED-refuted lane.
     *
     * @return array{0: Block, 1: Block}
     */
    private function tedRefutedPair(): array
    {
        $codeA = '<?php function probe_A($p) { $a = strlen($p); $b = $a + 1; $c = $b + 2; $d = $c * 3; return [$a, $b, $c, $d]; }';
        $codeB = '<?php function probe_B($p) { if ($p) { $a = strlen($p); $b = $a + 1; } else { $a = 0; $b = 0; } '
            . 'for ($i = 0; $i < 2; $i++) { $c = $b + $i; } $d = $c * 3; return [$a, $b, $c, $d]; }';

        $blocks = [];
        foreach ([['IR-A', $codeA], ['IR-B', $codeB]] as [$id, $code]) {
            $stmts = (new AstParser())->parseCode($code);
            $this->assertNotNull($stmts);
            $block = (new BlockExtractor(minSize: 1, maxSize: 100000))->extract('test.php', $stmts)[0];
            (new Normalizer(mode: 'aggressive'))->normalize($block);
            $block->structuralHash = (new SubtreeHasher())->hash($block->canonical);
            $block->id       = $id;
            $block->ngramBag = ['g_strlen' => 2, 'g_add' => 3, 'g_mul' => 1, 'g_ret' => 2];
            $block->irBag    = ['READ user' => 4];
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
