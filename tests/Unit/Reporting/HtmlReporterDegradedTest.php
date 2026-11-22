<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PhpParser\Node\Stmt\Nop;
use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\Hole;
use Phpdup\Reporting\HtmlReporter;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;
use PHPUnit\Framework\TestCase;

/**
 * W4-B fix M1 (review r1): the HTML hole table was the one shipped surface
 * that rendered degraded holes as an empty <td> while JSON said
 * degraded:true and CLI said (gap). Parity means a visible, honest marker.
 */
final class HtmlReporterDegradedTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string) sys_get_temp_dir() . '/phpdup-html-gap-' . uniqid('', true);
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function testDegradedHoleRendersGapMarkerNeverAnEmptyCell(): void
    {
        $cluster        = $this->pair();
        $cluster->holes = [new Hole('__P0', 'subtree', ['if ($s > 1)', Hole::MISSING], true)];

        $page = $this->renderClusterPage($cluster);

        $this->assertStringContainsString('<span class="gap">(gap)</span>', $page);
        $this->assertStringContainsString('if ($s &gt; 1)', $page, 'the real observation still renders escaped');
        $this->assertStringNotContainsString('<td></td>', $page, 'a degraded cell must never be silently empty');
        $this->assertStringNotContainsString('<missing>', $page);
    }

    public function testAbsentAndGapMarkersCoexistInOneCell(): void
    {
        // output-S27 canonical form: BOTH absences are now the single
        // Hole::MISSING row — the projection still splits them, because
        // the honest HTML differs (verified absence vs degraded gap).
        $cluster        = $this->pair();
        $cluster->holes = [
            new Hole('__P0', 'literal', [Hole::MISSING, "'x'"], true),
            new Hole('__P1', 'optional_block', [Hole::MISSING, "'y'"], false),
        ];

        $page = $this->renderClusterPage($cluster);

        $this->assertStringContainsString('<span class="gap">(gap)</span>', $page);
        $this->assertStringContainsString('<span class="absent">&lt;absent&gt;</span>', $page);
        $this->assertStringContainsString('<span class="gap">(gap)</span>, &#039;x&#039;', $page, 'gap joins the value list honestly');
    }

    public function testCanonicalRowOnDegradedOptionalHoleRendersGapNotAbsence(): void
    {
        // The other projection arm: a degraded optional hole cannot claim
        // its '' rows were observed at all — the cell must say (gap).
        $cluster        = $this->pair();
        $cluster->holes = [new Hole('__P1', 'optional_block', ["'y'", Hole::MISSING], true)];

        $page = $this->renderClusterPage($cluster);

        $this->assertStringContainsString('<span class="gap">(gap)</span>', $page);
        $this->assertStringNotContainsString('class="absent"', $page, 'ambiguous rows never claim verified absence');
    }

    public function testCssCarriesTheGapStyle(): void
    {
        $cluster        = $this->pair();
        $cluster->holes = [new Hole('__P0', 'literal', [Hole::MISSING, "'x'"], true)];
        $dir            = $this->tmp . '/out';

        (new HtmlReporter())->writeTo($this->report($cluster), $dir);
        $css = (string) file_get_contents($dir . '/style.css');

        $this->assertStringContainsString('.gap', $css);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function renderClusterPage(Cluster $cluster): string
    {
        $dir = $this->tmp . '/out';
        (new HtmlReporter())->writeTo($this->report($cluster), $dir);
        $pages = glob($dir . '/cluster-*.html') ?: [];
        $this->assertNotEmpty($pages);
        return (string) file_get_contents($pages[0]);
    }

    private function report(Cluster $cluster): Report
    {
        $cluster->signature ??= 'function thing(): mixed';
        $cluster->impact      = 2;
        return new Report(
            files: 2, blocks: 2, parseErrors: 0, clusters: [$cluster],
            config: new Config(paths: [$this->tmp], exclude: []),
        );
    }

    private function pair(): Cluster
    {
        $a = $this->member('a.php');
        $b = $this->member('b.php');
        return new Cluster('DEG1', [$a, $b], 0.9, false);
    }

    private function member(string $file): Block
    {
        $path = $this->tmp . '/' . $file;
        file_put_contents($path, "<?php\nif (\$s > 1) {\n    x();\n}\n");
        $block      = new Block($path, new LineRange(2, 4), 'method', 'Acme\\X', 'Host', 'run', new Nop());
        $block->id  = basename($file, '.php');
        return $block;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
