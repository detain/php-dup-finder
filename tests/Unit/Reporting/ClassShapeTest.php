<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 2.3 — the class-shape micro-fixture, end to end: the opt-in
 * roster over tests/Fixtures/class-shape/ must surface the doc's two
 * output classes on hermetic bytes — a declaration-TWIN pair (byte-equal
 * members, different names -> shape_exact cluster, every verdict
 * identical-in-all) and a template-method pair (literal-only body split
 * -> shape_exact cluster, the splitting method verdict parameterizable).
 * The divergent verdict is pinned at corpus scale in
 * MicroFixtureAuditTest (the preamble go() family) — a two-class
 * fixture file pair whose tails differ in SHAPE lands below the near-dup
 * threshold and correctly never clusters (no table to fake).
 *
 * The default-roster half is the never-implicit end-to-end guard: zero
 * class-kind clusters, zero class_shape keys, so the byte-frozen golden
 * payloads stay untouched by construction.
 */
final class ClassShapeTest extends TestCase
{
    /** @param list<string> $allowedKinds @return array<string,mixed> */
    private function runFixture(array $allowedKinds): array
    {
        $dir = realpath(__DIR__ . '/../../Fixtures/class-shape');
        $this->assertNotFalse($dir, 'class-shape fixture directory missing');
        $config = new Config(
            paths: [$dir],
            exclude: Config::defaults([])->exclude,
            maxDocumentFrequency: 0.9,
            minClusterImpact: 1,
            workers: 1,
            lazyAst: false,
            allowedKinds: $allowedKinds,
            cacheDir: sys_get_temp_dir() . '/phpdup-class-shape-' . bin2hex(random_bytes(4)),
        );
        $state = new PipelineState($config);
        $out = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);
        $report = new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: (new Ranker($config->minClusterImpact))->rank($state->clusters),
            config: $config,
        );
        $this->assertSame(0, $state->parseErrors, 'fixtures must parse cleanly');
        $this->assertCount(4, $state->files, 'fixture holds exactly four declarations');

        return (new JsonReporter())->build($report);
    }

    public function testOptInRosterFindsTheTwinPairAndTheTemplatePair(): void
    {
        $payload = $this->runFixture(['class']);
        $classClusters = array_values(array_filter(
            $payload['clusters'],
            static fn (array $c): bool => ($c['kind'] ?? null) === 'class',
        ));
        $this->assertCount(2, $classClusters, 'exactly the designed twin + template clusters (dv-class pairs would not cluster; none exist here)');

        $byName = [];
        foreach ($classClusters as $c) {
            $names = $c['class_shape']['class_names'];
            sort($names);
            $byName[implode(',', $names)] = $c;
        }
        $this->assertSame(
            ['LedgerWidgetState,PortalWidgetState', 'ImportJobAlpha,ImportJobBeta'],
            array_keys($byName),
            'class_shape names the two members of each family (Ranker orders clusters by impact desc: twins first)',
        );

        $twins = $byName['LedgerWidgetState,PortalWidgetState'];
        $this->assertSame(1.0, (float) $twins['similarity'], 'declaration twins are byte-equal after the name blank');
        $this->assertTrue($twins['class_shape']['shape_exact']);
        foreach (['refresh', 'version'] as $method) {
            $this->assertSame(
                ['members_with_method' => 2, 'distinct_strict_hashes' => 1, 'distinct_fold_hashes' => 1, 'verdict' => 'identical-in-all'],
                $twins['class_shape']['shared_methods'][$method],
                "twin method {$method}: byte-equal bodies pull up as-is",
            );
        }

        $tpl = $byName['ImportJobAlpha,ImportJobBeta'];
        $this->assertSame(1.0, (float) $tpl['similarity'], 'literal-only body splits fold to one skeleton');
        $this->assertTrue($tpl['class_shape']['shape_exact']);
        $this->assertSame(
            ['members_with_method' => 2, 'distinct_strict_hashes' => 2, 'distinct_fold_hashes' => 1, 'verdict' => 'parameterizable'],
            $tpl['class_shape']['shared_methods']['label'],
            'label(): strict lane sees two spellings, fold lane one shape -> template-method proposal',
        );
        $this->assertSame(
            ['members_with_method' => 2, 'distinct_strict_hashes' => 1, 'distinct_fold_hashes' => 1, 'verdict' => 'identical-in-all'],
            $tpl['class_shape']['shared_methods']['drain'],
            'drain(): byte-equal across the pair',
        );
        $this->assertSame(12, (int) $tpl['impact'], 'line-scale impact matches the measured twin span');
    }

    public function testDefaultRosterMintsNoClassClustersAndNoClassShapeKeys(): void
    {
        $payload = $this->runFixture([]);
        foreach ($payload['clusters'] as $c) {
            $this->assertNotSame('class', $c['kind'] ?? null, 'never-implicit law: default roster must not mint class clusters');
            $this->assertArrayNotHasKey('class_shape', $c, 'class_shape is a conditional key: absent from every default cluster payload');
        }
    }
}
