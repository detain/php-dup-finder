<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\JsonSchemaSpec;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 6.4 (THEME): the theming demand at the stage seam — the rollups
 * family (ReportStageRollupsTest) mirrored onto the report-stage leg:
 *
 *  1. demand-off inertness — unarmed runs never construct the analyzer
 *     payload (themes stays null through state->report, and the JSON
 *     summary carries NO key: the frozen-goldens posture from the other
 *     side of the seam);
 *  2. armed carry — the payload rides the §5f trailing-param seam into
 *     the Report, the REPORTED findings feed it (accounting invariant:
 *     themed + themeless == clusters, every claim bounded), and
 *     JsonReporter appends the conditional block after rollups in the
 *     declared spec order (summaryShape(themes: true)/themeBlockShape());
 *  3. basis honesty — basis.call_census mirrors whether the call-sequence
 *     census actually reached the analyzer through the seam (armed
 *     call_sequence flips it true; unarmed stays false);
 *  4. an armed run over a findings-free corpus is the zero-shaped block,
 *     never null — "measured and empty" vs "not measured" stay
 *     distinguishable for consumers.
 *
 * Exact theme CONTENT (which seed wins, labels, caps) is pinned by the
 * deterministic ThemeAnalyzerTest over hand-built clusters and census
 * objects; this seam keeps to the laws that only the live pipeline can
 * witness (payload shape, ordering, carriers, inertness).
 */
final class ReportStageThemesTest extends TestCase
{
    private const SHARED_BODY = <<<'PHP'
        $slug = strtolower(trim($key));
        if ($slug === '') { $slug = 'anon'; }
        return $slug;
    PHP;

    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }

    /**
     * @param array<string,string> $files filename => contents
     * @param array<string,mixed>  $configOverrides
     */
    private function drive(array $files, array $configOverrides): PipelineState
    {
        $dir = sys_get_temp_dir() . '/phpdup_themestage_' . uniqid('', true);
        mkdir($dir, 0o775, true);
        $this->tmpDirs[] = $dir;
        foreach ($files as $name => $body) {
            file_put_contents($dir . '/' . $name, $body);
        }
        $config = new Config(...array_merge([
            'paths'            => [$dir],
            'exclude'          => [],
            'lazyAst'          => false,
            'minClusterImpact' => 1,
        ], $configOverrides));
        $state = new PipelineState($config);
        $out = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);
        (new ReportStage(50, false))->run($state, $out);
        return $state;
    }

    /** @return array<string,string> the two-file mirror twin corpus */
    private function twinCorpus(): array
    {
        return [
            'gamma.php' => "<?php\nclass Gamma {\n    public function left(string \$key): string {\n        "
                . str_replace("\n", "\n        ", self::SHARED_BODY) . "\n    }\n}\n",
            'delta.php' => "<?php\nclass Delta {\n    public function right(string \$key): string {\n        "
                . str_replace("\n", "\n        ", self::SHARED_BODY) . "\n    }\n}\n",
        ];
    }

    public function testUnarmedRunCarriesNoThemesThroughTheWholeSeam(): void
    {
        $state = $this->drive($this->twinCorpus(), ['themes' => false]);
        $this->assertNotNull($state->report);
        $this->assertNull($state->report->themes, 'the analyzer must not run unarmed');
        $summary = (new JsonReporter())->build($state->report)['summary'];
        $this->assertArrayNotHasKey('themes', $summary);
        $this->assertSame(array_keys(JsonSchemaSpec::summaryShape(false)), array_keys($summary));
    }

    public function testArmedRunCarriesThemesIntoReportAndSummaryAfterRollups(): void
    {
        $state = $this->drive($this->twinCorpus(), ['themes' => true, 'corpusRollups' => true]);
        $themes = $state->report->themes;
        $this->assertIsArray($themes, 'the armed run must carry a payload');
        $this->assertSame(array_keys(JsonSchemaSpec::themeBlockShape()), array_keys($themes));
        $this->assertFalse($themes['basis']['call_census'], 'call_sequence unarmed -> degraded basis, disclosed');

        // Accounting invariants (content-agnostic; the analyzer unit
        // suite owns WHICH seed wins once the census hand-off is pinned).
        $reported = (new JsonReporter())->build($state->report)['summary']['clusters'];
        $this->assertSame($reported, $themes['totals']['clusters'], 'themes group the REPORTED findings, never the full ranked set');
        $this->assertSame(
            $themes['totals']['clusters'],
            $themes['totals']['themed_clusters'] + $themes['totals']['themeless_clusters'],
            'every finding is themed or counted in the residual — never silence',
        );
        $claimSum = array_sum(array_column($themes['themes'], 'clusters'));
        $this->assertSame($themes['totals']['themed_clusters'], $claimSum, 'a cluster joins at most one theme');
        $this->assertGreaterThanOrEqual(1, $themes['totals']['themes'], 'the root-level directory seeds both twin findings');

        $summaryKeys = array_keys((new JsonReporter())->build($state->report)['summary']);
        $this->assertSame(
            array_merge(array_keys(JsonSchemaSpec::summaryShape(false)), ['rollups', 'themes']),
            $summaryKeys,
            'armed summary = default key set + rollups + trailing themes',
        );
        $this->assertSame(array_keys(JsonSchemaSpec::summaryShape(rollups: true, themes: true)), $summaryKeys);
    }

    public function testArmedCallCensusFlipsTheBasisThroughTheSeam(): void
    {
        // Two INDEPENDENT twin pairs (distinct bodies) plus the census
        // arm: the seam must hand the live census to the analyzer
        // (basis flips), and with >= 2 reported findings under one
        // directory the account must close themed (whatever seeds claim
        // them — vocabulary when call tokens span clusters, the directory
        // otherwise — is the analyzer unit suite's deterministic pin).
        $counter = static fn(string $cls): string => "<?php\nclass $cls {\n"
            . "    public function tick(int \$x): int {\n        \$n = intval(\$x) + 1;\n"
            . "        if (\$n > 9) { \$n = 1; }\n        return \$n;\n    }\n}\n";
        $state = $this->drive([
            'gamma.php' => "<?php\nclass Gamma {\n    public function left(string \$key): string {\n        "
                . str_replace("\n", "\n        ", self::SHARED_BODY) . "\n    }\n}\n",
            'delta.php' => "<?php\nclass Delta {\n    public function right(string \$key): string {\n        "
                . str_replace("\n", "\n        ", self::SHARED_BODY) . "\n    }\n}\n",
            'kappa.php' => $counter('Kappa'),
            'lambda.php' => $counter('Lambda'),
        ], [
            // call_sequence's shipped precondition (§5c): the
            // fragment-gated capture rides idiom_mining plus an explicit
            // fragment kind.
            'themes'       => true,
            'callSequence' => true,
            'idiomMining'  => true,
            'allowedKinds' => ['method', 'class', 'fragment'],
        ]);
        $themes = $state->report->themes;
        $this->assertIsArray($themes);
        $this->assertTrue($themes['basis']['call_census'], 'the armed census must actually reach the analyzer');
        $this->assertGreaterThanOrEqual(2, $themes['totals']['clusters']);
        $this->assertGreaterThanOrEqual(2, $themes['totals']['themed_clusters']);
        $this->assertSame(
            $themes['totals']['clusters'],
            $themes['totals']['themed_clusters'] + $themes['totals']['themeless_clusters'],
        );
    }

    public function testArmedRunWithoutFindingsIsTheZeroBlockNeverNull(): void
    {
        // ReportStage short-circuits "scanner found no files" before any
        // mount, so the zero-shape is proven at the honest floor: files
        // present, no comparable findings anywhere. Measured-and-empty
        // still carries the block; only UNARMED omits the key.
        $state = $this->drive(['plain.php' => "<?php\ndefine('X', 1);\n"], ['themes' => true]);
        $themes = $state->report->themes;
        $this->assertIsArray($themes);
        $this->assertSame(0, $themes['totals']['clusters']);
        $this->assertSame(0, $themes['totals']['themes']);
        $this->assertSame([], $themes['themes']);
        $this->assertSame(0, $themes['themes_truncated']);
        $summary = (new JsonReporter())->build($state->report)['summary'];
        $this->assertArrayHasKey('themes', $summary, 'measured-and-empty still shows the key; only UNARMED omits it');
    }
}
