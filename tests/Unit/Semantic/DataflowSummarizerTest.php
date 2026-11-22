<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Semantic;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Ml\PairFeatures;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Semantic\DataflowSummarizer;

final class DataflowSummarizerTest extends TestCase
{
    public function testSummarizeDoesNotFatal(): void
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode('<?php function compute($x, $y) { $sum = $x + $y; return $sum; }');
        $this->assertNotNull($stmts);
        $this->assertCount(1, $stmts);

        $summarizer = new DataflowSummarizer();
        $result = $summarizer->summarize($stmts[0]);

        $this->assertCount(4, $result);
        $this->assertArrayHasKey('vars', $result);
        $this->assertArrayHasKey('returns', $result);
        $this->assertArrayHasKey('calls', $result);
        $this->assertArrayHasKey('sideEffects', $result);
        $this->assertFalse($result['sideEffects']);
    }

    /**
     * engine-028 fold-in pin (plan "051 word-boundary unit pin",
     * leg 1/2): the former `str_contains($lower, 'log'/'fire'/…)`
     * substring test flagged any name merely CONTAINING a verb.
     * Whole-word matching must clear the false positives while the
     * calls multiset keeps counting them (flag and counter are
     * independent legs).
     */
    public function testSubstringLookalikeNamesAreNotSideEffects(): void
    {
        foreach (['catalog', 'dialog', 'blog', 'firewall', 'prolog'] as $lookalike) {
            $stmts = (new AstParser())->parseCode(
                "<?php function f(\$svc) { \$svc->{$lookalike}(); }"
            );
            $this->assertNotNull($stmts);
            $summary = (new DataflowSummarizer())->summarize($stmts[0]);
            $this->assertFalse(
                $summary['sideEffects'],
                "method '{$lookalike}()' contains a verb as a substring but is not a side effect"
            );
            $this->assertArrayHasKey(
                $lookalike,
                $summary['calls'],
                "the calls multiset must still count '{$lookalike}()' — only the flag narrowed"
            );
        }
    }

    /**
     * engine-028 fold-in pin (leg 2/2): verb WORDS at any position
     * still match — bare, camelCase-prefixed, snake_cased, and the
     * `__CALL_<name>` sentinels aggressive-mode canonical ASTs
     * actually carry (PairFeatures summarizes those, not raw names).
     */
    public function testVerbWordMatchesAcrossNameShapes(): void
    {
        foreach (['log', 'logError', 'sendEmail', 'notify_all', '__CALL_log', 'chatLogger'] as $verbName) {
            $stmts = (new AstParser())->parseCode(
                "<?php function f(\$svc) { \$svc->{$verbName}(); }"
            );
            $this->assertNotNull($stmts);
            $this->assertTrue(
                (new DataflowSummarizer())->summarize($stmts[0])['sideEffects'],
                "method '{$verbName}()' carries a side-effect verb as a full word"
            );
        }

        // The stamped lookalike must stay false too — the sentinel
        // split ([CALL, catalog]) must not resurrect the substring
        // false positive inside the stamped shape.
        $stmts = (new AstParser())->parseCode('<?php function f($svc) { $svc->__CALL_catalog(); }');
        $this->assertNotNull($stmts);
        $this->assertFalse((new DataflowSummarizer())->summarize($stmts[0])['sideEffects']);
    }

    /**
     * Wire-feature honesty (plan "Shared resources": the change must
     * not alter ML features): PairFeatures reads vars/calls/returns
     * only — the sideEffects flag never enters the vector. The fixed
     * 12-key wire shape is pinned here for a block pair sitting
     * exactly on the old false-positive boundary (`catalog` was
     * side-effect-flagged by substring, now is not); end-to-end
     * value identity stays pinned by the pre-change capture in
     * tests/Unit/Ml/PairFeaturesTest.
     */
    public function testPairFeatureVectorIsInertToTheSideEffectFlag(): void
    {
        $catalog = $this->buildNormalizedBlock('<?php function f($s) { $s->catalog(); return 1; }');
        $dialog  = $this->buildNormalizedBlock('<?php function f($s) { $s->dialog(); return 1; }');
        $log     = $this->buildNormalizedBlock('<?php function f($s) { $s->log(); return 1; }');

        foreach ([(new PairFeatures())->extract($catalog, $dialog), (new PairFeatures())->extract($catalog, $log)] as $vector) {
            $this->assertSame(
                [
                    'feature_version', 'structural_hash_match', 'ngram_jaccard',
                    'var_jaccard', 'call_jaccard', 'return_jaccard',
                    'db_tag_jaccard', 'ir_token_jaccard', 'block_size_ratio',
                    'kind_match', 'block_a_kind', 'block_b_kind',
                ],
                array_keys($vector),
                'the sideEffects flag must not enter — nor may any fix to it move — the wire shape'
            );
        }
    }

    /**
     * Production-shape block: aggressive normalization, so call
     * names carry the `__CALL_` sentinels PairFeatures actually
     * feeds to DataflowSummarizer.
     */
    private function buildNormalizedBlock(string $code): \Phpdup\Extraction\Block
    {
        $stmts = (new AstParser())->parseCode($code);
        $this->assertNotNull($stmts);
        $blocks = (new BlockExtractor(minSize: 1))->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        $block = $blocks[0];
        (new Normalizer(mode: 'aggressive'))->normalize($block);
        $block->structuralHash = (new SubtreeHasher())->hash($block->canonical);
        $block->ngramBag = (new NgramFingerprint(3))->fingerprint($block->canonical);
        return $block;
    }
}
