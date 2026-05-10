<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ml;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Ml\PairFeatures;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;

final class PairFeaturesTest extends TestCase
{
    public function testFeatureVersionIsStable(): void
    {
        $this->assertSame(1, PairFeatures::FEATURE_VERSION);
        $this->assertSame(1, PairFeatures::featureVersion());
    }

    public function testIdenticalBlocksProduceMaxScores(): void
    {
        $code = '<?php function f($id) { return User::find($id); }';
        $a = $this->buildBlock($code);
        $b = $this->buildBlock($code);
        $features = (new PairFeatures())->extract($a, $b);

        $this->assertSame(1, $features['feature_version']);
        $this->assertTrue($features['structural_hash_match']);
        $this->assertSame(1.0, $features['ngram_jaccard']);
        $this->assertSame(1.0, $features['ir_token_jaccard']);
        $this->assertSame(1.0, $features['db_tag_jaccard']);
        $this->assertSame(1.0, $features['block_size_ratio']);
        $this->assertTrue($features['kind_match']);
        $this->assertSame('function', $features['block_a_kind']);
    }

    public function testCrossLibraryReadsHaveHighDbTagAndIrSignal(): void
    {
        $a = $this->buildBlock('<?php function f($id) { return User::find($id); }');
        $b = $this->buildBlock('<?php function f($pdo, $id) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        }');
        $features = (new PairFeatures())->extract($a, $b);
        // Surface ngram Jaccard is low (different call shapes), but
        // the DB-tag and IR bands should fire.
        $this->assertGreaterThan(0.0, $features['db_tag_jaccard']);
        $this->assertGreaterThan(0.0, $features['ir_token_jaccard']);
    }

    public function testUnrelatedBlocksHaveLowerSignal(): void
    {
        $a = $this->buildBlock('<?php function f($x) { return $x + 1; }');
        $b = $this->buildBlock('<?php function f($pdo, $id) { return $pdo->query("SELECT * FROM users"); }');
        $features = (new PairFeatures())->extract($a, $b);
        $this->assertFalse($features['structural_hash_match']);
        // Surface n-gram Jaccard captures the call-shape mismatch.
        $this->assertLessThan(0.5, $features['ngram_jaccard']);
        // IR-token Jaccard is also low when the operation shapes
        // differ (one is pure arithmetic, the other a DB query).
        $this->assertLessThan(0.7, $features['ir_token_jaccard']);
    }

    public function testFeatureBundleIsJsonSerialisable(): void
    {
        $a = $this->buildBlock('<?php function f($id) { return User::find($id); }');
        $b = $this->buildBlock('<?php function f($id) { return $id; }');
        $features = (new PairFeatures())->extract($a, $b);
        $json = json_encode($features);
        $this->assertIsString($json);
        $this->assertNotFalse($json);
        $this->assertNotEmpty($json);
    }

    public function testKindMatchReflectsBlockKind(): void
    {
        $a = $this->buildBlock('<?php function f($x) { return $x; }');
        $b = $this->buildBlock('<?php $f = function($x) { return $x; };');
        $features = (new PairFeatures())->extract($a, $b);
        $this->assertFalse($features['kind_match']);
        $this->assertSame('function', $features['block_a_kind']);
        $this->assertSame('closure', $features['block_b_kind']);
    }

    private function buildBlock(string $code): \Phpdup\Extraction\Block
    {
        $stmts = (new AstParser())->parseCode($code);
        $blocks = (new BlockExtractor(minSize: 1))->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        $block = $blocks[0];
        (new Normalizer(mode: 'aggressive'))->normalize($block);
        $block->structuralHash = (new SubtreeHasher())->hash($block->canonical);
        $block->ngramBag = (new NgramFingerprint(3))->fingerprint($block->canonical);
        return $block;
    }
}
