<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\HoleCoverage;
use Phpdup\Reporting\CliReporter;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Item 5.4 CLI surface: structural gaps render as (gap) with a muted
 * partial-evidence note, and the hole-coverage stance prints its
 * re-expressed annotation only when the operator lowered the bar —
 * the default 1.0 posture must never add a line.
 */
final class CliReporterHonestyTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string)sys_get_temp_dir() . '/phpdup-cli-honesty-' . uniqid('', true);
        mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    public function testDegradedHoleRendersGapMarkerAndPartialEvidenceNote(): void
    {
        $cluster = $this->pairCluster();
        $cluster->holes[] = new Hole('__P9', 'subtree', ['', "'kept'"], true);

        $rendered = $this->render($cluster, 1.0);

        $this->assertStringContainsString('(gap)', $rendered);
        $this->assertStringNotContainsString('<missing>', $rendered);
        $this->assertStringContainsString('no node at the hole path', $rendered);
        $this->assertStringContainsString('partial evidence', $rendered);
        // The stance line must NOT piggyback on a degraded-only cluster at
        // the default threshold.
        $this->assertStringNotContainsString('re-expressed:', $rendered);
    }

    public function testCoverageStancePrintsWhenBarLowered(): void
    {
        $cluster = $this->pairCluster();
        $coverage = HoleCoverage::coverage($cluster);
        $this->assertNotNull($coverage);
        $this->assertGreaterThan(0.0, $coverage, 'fixture must leave measurable hole coverage');

        $rendered = $this->render($cluster, $coverage / 2);

        $this->assertStringContainsString('re-expressed:', $rendered);
        $this->assertStringContainsString('--max-hole-coverage', $rendered);
    }

    public function testDefaultThresholdAddsNoStanceLine(): void
    {
        $cluster = $this->pairCluster();

        $rendered = $this->render($cluster, 1.0);

        $this->assertStringNotContainsString('re-expressed:', $rendered);
    }

    public function testSummaryOnlyStaysSilentOnHonestyNotes(): void
    {
        $cluster = $this->pairCluster();
        $cluster->holes[] = new Hole('__P9', 'subtree', ['', 'x'], true);
        $config = new Config(paths: [$this->tmp], exclude: [], maxHoleCoverage: 0.01);
        $out = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        (new CliReporter(CliReporter::VERBOSITY_SUMMARY_ONLY))
            ->render($this->report($cluster, $config), $out);

        $this->assertStringNotContainsString('no node at the hole path', $out->fetch());
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function render(Cluster $cluster, float $maxHoleCoverage): string
    {
        $config = new Config(paths: [$this->tmp], exclude: [], maxHoleCoverage: $maxHoleCoverage);
        $out = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        (new CliReporter())->render($this->report($cluster, $config), $out);
        return $out->fetch();
    }

    private function report(Cluster $cluster, Config $config): Report
    {
        $cluster->signature = 'function extracted($v): mixed';
        $cluster->impact    = 2;

        return new Report(
            files: 2, blocks: 2, parseErrors: 0, clusters: [$cluster], config: $config,
        );
    }

    private function pairCluster(): Cluster
    {
        $blocks = [];
        foreach (['if ($s > 1) { send($u); }', 'if ($s > 2) { send($v); }'] as $i => $code) {
            $file  = $this->tmp . "/m{$i}.php";
            file_put_contents($file, "<?php\n" . $code . "\n");
            $stmts     = (new AstParser())->parseCode((string)file_get_contents($file));
            $extracted = (new BlockExtractor(minSize: 1))->extract($file, $stmts);
            $this->assertNotEmpty($extracted);
            $block       = $extracted[0];
            $block->id   = "BLK{$i}";
            $block->name = 'm' . $i;
            $blocks[]    = $block;
        }
        $cluster        = new Cluster('PAIR', $blocks, 0.9, false);
        $cluster->pairs = [[
            'blockA' => 'BLK0', 'blockB' => 'BLK1', 'matchTier' => 'jaccard', 'matchScore' => 0.9,
        ]];
        (new AntiUnifier())->unify($cluster);
        $this->assertNotEmpty($cluster->holes);
        return $cluster;
    }
}
