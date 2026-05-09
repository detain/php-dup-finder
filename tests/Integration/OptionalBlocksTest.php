<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Symfony\Component\Console\Output\NullOutput;

/**
 * End-to-end test for the type-3 / optional-segment clone detection.
 *
 * tests/Fixtures/optional/Pair.php contains two methods whose if-bodies share
 * the same first three statements, with the longer method having two extra
 * statements at the end. The pipeline should:
 *   1. cluster the two if-blocks via the containment fallback (Jaccard alone
 *      is too low at ~0.6 — Jaccard rejects them);
 *   2. anti-unify with the longer one as seed and emit two optional_block
 *      holes for the trailing two statements;
 *   3. synthesize default-false bool params named after the calls in the
 *      missing statements;
 *   4. tag the cluster as 'optional-segments'.
 */
final class OptionalBlocksTest extends TestCase
{
    public function testTypeThreeOptionalSegmentClusterFormsWithBooleanParams(): void
    {
        $config = new Config(
            paths: [__DIR__ . '/../Fixtures/optional'],
            exclude: Config::defaults([])->exclude,
            minBlockSize: 4,
            // Tiny fixture — every n-gram is "common" by ratio so the default
            // 0.01 max_df strips them all out. Lift it for the test.
            maxDocumentFrequency: 0.5,
            minClusterImpact: 1,
            lazyAst: false,
        );
        $cluster = $this->runPipeline($config);

        $this->assertNotNull($cluster, 'optional-segment cluster should form');
        $this->assertContains('optional-segments', $cluster->patternTags);

        $optional = array_values(array_filter(
            $cluster->holes,
            static fn($h) => $h->kind === 'optional_block',
        ));
        $this->assertGreaterThanOrEqual(2, count($optional), 'expected at least the two trailing statements as optional holes');

        // Inferred type is bool; suggested name is $include<Verb>; defaulted via signature.
        foreach ($optional as $hole) {
            $this->assertSame('bool', $hole->inferredType);
            $this->assertStringStartsWith('$include', $hole->suggestedName, 'optional bools should be named $include*');
        }

        $this->assertNotNull($cluster->signature);
        $this->assertStringContainsString('= false', (string)$cluster->signature, 'signature must default the optional bools');

        // The "<absent>" sentinel should appear at exactly one member position
        // per optional hole — the short member.
        foreach ($optional as $hole) {
            $absent = array_filter($hole->observedValues, static fn($v) => $v === '<absent>');
            $this->assertCount(1, $absent, 'exactly one member should be marked <absent> per optional hole');
        }
    }

    public function testFeatureCanBeDisabledViaConfig(): void
    {
        $config = new Config(
            paths: [__DIR__ . '/../Fixtures/optional'],
            exclude: Config::defaults([])->exclude,
            minBlockSize: 4,
            maxDocumentFrequency: 0.5,
            minClusterImpact: 1,
            lazyAst: false,
            optionalBlocksEnabled: false,
        );
        $cluster = $this->runPipeline($config);

        // With detection disabled, the containment fallback never fires and no
        // optional cluster forms (Jaccard alone rejects the pair).
        $this->assertNull($cluster, 'optional-block detection disabled means no cluster');
    }

    private function runPipeline(Config $config): ?\Phpdup\Clustering\Cluster
    {
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        foreach ($state->clusters as $c) {
            foreach ($c->patternTags as $tag) {
                if ($tag === 'optional-segments') return $c;
            }
            // The cluster might exist but not be tagged yet (PatternRecognizer runs
            // inside RefactorStage). If we got here without seeing the tag, but the
            // cluster has optional_block holes, also return it.
            foreach ($c->holes as $h) {
                if ($h->kind === 'optional_block') return $c;
            }
        }
        return null;
    }
}
