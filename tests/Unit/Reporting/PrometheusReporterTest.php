<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\PrometheusReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Util\ControlText;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;

final class PrometheusReporterTest extends TestCase
{
    public function testEmptyReportEmitsZeroGauges(): void
    {
        $text = (new PrometheusReporter())->build(
            new Report(0, 0, 0, [], Config::defaults([])),
        );
        $this->assertStringContainsString('# TYPE phpdup_clusters_total gauge', $text);
        $this->assertStringContainsString("phpdup_clusters_total 0", $text);
        $this->assertStringContainsString("phpdup_files_scanned 0", $text);
        $this->assertStringContainsString("phpdup_duplicates_total 0", $text);
        // Bounded-vocabulary families stay out of the exposition entirely
        // when the corpus contributes no values (the pattern-tag posture).
        $this->assertStringNotContainsString('phpdup_severity_clusters', $text);
        $this->assertStringNotContainsString('phpdup_kind_blocks', $text);
    }

    public function testFixtureReportEmitsNonZeroClusterCount(): void
    {
        $report = $this->buildReport();
        $text   = (new PrometheusReporter())->build($report);

        $this->assertStringContainsString('# HELP phpdup_clusters_total', $text);
        $this->assertMatchesRegularExpression('/^phpdup_clusters_total \d+$/m', $text);
        $this->assertMatchesRegularExpression('/^phpdup_total_impact \d+$/m', $text);

        // Aggregate replacements for the retired per-cluster series (output-026)
        $this->assertMatchesRegularExpression('/^phpdup_duplicates_total \d+$/m', $text);
        $this->assertMatchesRegularExpression('/^phpdup_severity_clusters\{severity="[^"]+"\} \d+$/m', $text);
        $this->assertMatchesRegularExpression('/^phpdup_kind_blocks\{kind="[^"]+"\} \d+$/m', $text);
    }

    public function testTextEndsWithNewline(): void
    {
        $text = (new PrometheusReporter())->build(
            new Report(0, 0, 0, [], Config::defaults([])),
        );
        $this->assertStringEndsWith("\n", $text);
    }

    // ── output-026: high-cardinality per-cluster series retired ────────────

    /**
     * Metric-name change, pinned: the content-hash-labeled per-cluster
     * `phpdup_cluster_impact{id="<hash>",kind="..."}` series is GONE —
     * ids are content-derived and churn on every edit, an unbounded
     * cardinality load on a cron-fed TSDB. `phpdup_duplicates_total` plus
     * the by-severity/by-kind aggregates replace it; per-cluster detail
     * lives in JSON/HTML instead, and NO flag re-enables the id series
     * (the plan ruling recorded on output-S20).
     */
    public function testPerClusterIdSeriesReplacedByBoundedAggregates(): void
    {
        $report = $this->buildReport();
        $text   = (new PrometheusReporter())->build($report);

        $this->assertStringNotContainsString('phpdup_cluster_impact', $text);
        $this->assertStringNotContainsString('{id=', $text);
        $this->assertMatchesRegularExpression('/^phpdup_duplicates_total \d+$/m', $text);

        // Severity tiers label only the four forImpact() names, and the
        // kind family sums to duplicates_total — bounded label sets.
        $severity = $this->labelValues($text, 'phpdup_severity_clusters');
        $this->assertNotEmpty($severity);
        $this->assertSame([], array_diff(array_keys($severity), ['High', 'Medium', 'Low', 'Info']));
        $duplicates = $this->scalarGauge($text, 'phpdup_duplicates_total');
        $kindSum    = 0;
        foreach ($this->labelValues($text, 'phpdup_kind_blocks') as $n) {
            $kindSum += $n;
        }
        $this->assertSame($duplicates, $kindSum, 'kind_blocks sum equals duplicates_total');
    }

    public function testAggregatesCarryExactCountsAndSeverityTiers(): void
    {
        $clusters = [
            // impact 150 → High, members method×2
            $this->cluster('hi', 150, ['method', 'method'], ['sql-builder']),
            // impact 10 → Info, members function×1 + method×1
            $this->cluster('lo', 10, ['function', 'method'], []),
        ];
        $text = (new PrometheusReporter())->build(
            new Report(files: 4, blocks: 3, parseErrors: 0, clusters: $clusters, config: Config::defaults([])),
        );

        $this->assertSame(2, $this->scalarGauge($text, 'phpdup_clusters_total'));
        $this->assertSame(4, $this->scalarGauge($text, 'phpdup_duplicates_total'));
        $this->assertSame(160, $this->scalarGauge($text, 'phpdup_total_impact'));

        $this->assertSame(
            ['High' => 1, 'Info' => 1],
            $this->labelValues($text, 'phpdup_severity_clusters'),
        );
        $this->assertSame(
            ['function' => 1, 'method' => 3],
            $this->labelValues($text, 'phpdup_kind_blocks'),
        );
        $this->assertSame(
            ['sql-builder' => 1],
            $this->labelValues($text, 'phpdup_pattern_tag_clusters'),
        );
    }

    // ── output-047: label escaping (the Go-client escape set + sweeps) ─────

    public function testCarriageReturnInKindLabelIsEscapedNotRaw(): void
    {
        $report = $this->syntheticReport(kind: "k\rforger");
        $text   = (new PrometheusReporter())->build($report);

        // Raw CR would break line framing on tolerant readers; the value
        // must arrive as the two-char \r escape on ONE exposition line.
        $this->assertStringNotContainsString("\r", $text);
        $this->assertStringContainsString('kind="k\rforger"', $text);
        $this->assertMatchesRegularExpression(
            '/^phpdup_kind_blocks\{kind="k\\\\rforger"\} \d+$/',
            $this->sampleLine($text, 'kind="k\rforger"'),
        );
    }

    public function testUnescapableBytesAreSubstitutedInLabels(): void
    {
        $report = $this->syntheticReport(tag: "nul\x00and\x1besc");
        $text   = (new PrometheusReporter())->build($report);

        $this->assertStringNotContainsString("\x00", $text);
        $this->assertStringNotContainsString("\x1b", $text);
        $this->assertSame(2, substr_count($text, ControlText::SUBSTITUTE));
    }

    public function testLineFeedInTagLabelStaysEscapedNoStatementSplit(): void
    {
        $report = $this->syntheticReport(tag: "t\nphpdup_forged_metric 1");
        $text   = (new PrometheusReporter())->build($report);

        // The classic forgery: an LF label value minting a second metric
        // line. It must not appear as a standalone exposition line.
        $this->assertMatchesRegularExpression('/^phpdup_\w+ /m', $text);
        $this->assertStringNotContainsString("\nphpdup_forged_metric 1", $text);
        $this->assertStringNotContainsString("\r", $text);
        $this->assertStringContainsString('tag="t\nphpdup_forged_metric 1"', $text);
    }

    // ── once-S101 (php-dup-finder-once-126): strict exposition re-verify ────

    public function testFullExpositionParsesUnderStrictGrammarWithoutIdLabels(): void
    {
        $text = (new PrometheusReporter())->build($this->buildReport());
        $this->assertExpositionGrammar($text);

        // Cardinality law (output-026, default re-verified by output-S20):
        // tag/severity/kind are the WHOLE label vocabulary. The retired
        // per-cluster id series is the anti-pattern the aggregates replace,
        // so 'id' must appear in no label set on any sample line — not
        // merely as a missing metric name.
        $labelNames = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*\{([^\}]*)\}/', $line, $m) === 1) {
                preg_match_all('/(^|,)\s*([a-zA-Z_][a-zA-Z0-9_]*)=/', $m[1], $names);
                foreach ($names[2] as $name) {
                    $labelNames[$name] = true;
                }
            }
        }
        $this->assertNotEmpty($labelNames, 'the fixture corpus must exercise the labeled families');
        $found = array_keys($labelNames);
        sort($found);
        $this->assertSame(['kind', 'severity', 'tag'], $found);
    }

    public function testEscapedLabelOutputStillParsesUnderStrictGrammar(): void
    {
        // The integration the escape-era arms stop short of: labels forged
        // from raw CR/LF/NUL/ESC bytes, once escaped, must still be
        // grammatical exposition — a reader that rejects the whole payload
        // would be as bad as a split line.
        $reporter = new PrometheusReporter();
        $this->assertExpositionGrammar($reporter->build(
            $this->syntheticReport(kind: "k\rforge", tag: "t\nphpdup_forged 1"),
        ));
        $this->assertExpositionGrammar($reporter->build(
            $this->syntheticReport(tag: "nul\x00and\x1besc"),
        ));
    }

    /**
     * Line-by-line strict parse of the text-based exposition format:
     * `# HELP <metric> <printable text>`, `# TYPE <metric> gauge`, and
     * samples `metric{label="escaped value"} value` with a metric name
     * matching `[a-zA-Z_:][a-zA-Z0-9_:]*`, label values free of raw C0
     * bytes inside the quotes, and integer/float samples only after a
     * known `# TYPE`. Any line the grammar cannot eat fails the test.
     */
    private function assertExpositionGrammar(string $text): void
    {
        $lines = explode("\n", $text);
        $this->assertSame('', (string)array_pop($lines), 'exposition ends with a newline');
        $typed = [];
        foreach ($lines as $no => $line) {
            if ($line === '') {
                $this->fail("blank line at exposition line {$no}");
            }
            if (str_starts_with($line, '#')) {
                if (preg_match('/^# HELP [a-zA-Z_:][a-zA-Z0-9_:]* [\x20-\x7e\x80-\xff]+$/', $line) === 1) {
                    continue;
                }
                if (preg_match('/^# TYPE ([a-zA-Z_:][a-zA-Z0-9_:]*) gauge$/', $line, $m) === 1) {
                    $typed[$m[1]] = true;
                    continue;
                }
                $this->fail("ungrammatical comment line {$no}: {$line}");
            }
            $this->assertSame(
                1,
                preg_match(
                    '/^([a-zA-Z_:][a-zA-Z0-9_:]*)(\{[a-zA-Z_][a-zA-Z0-9_]*="(?:[^"\\\\\x00-\x1f]|\\\\.)*"\})?'
                    . ' (?:[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|NaN|[+-]Inf)$/',
                    $line,
                    $m,
                ),
                "ungrammatical sample line {$no}: {$line}",
            );
            $this->assertArrayHasKey($m[1], $typed, "sample for {$m[1]} precedes its # TYPE line");
        }
    }

    /** @return string the single exposition line carrying $needle */
    private function sampleLine(string $text, string $needle): string
    {
        foreach (explode("\n", $text) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }
        $this->fail("no line carries {$needle}");
    }

    /** Read one unlabeled scalar gauge value out of the exposition. */
    private function scalarGauge(string $text, string $name): int
    {
        $this->assertSame(1, preg_match('/^' . preg_quote($name, '/') . ' (\d+)$/m', $text, $m));
        return (int) $m[1];
    }

    /** @return array<string,int> label value => gauge value */
    private function labelValues(string $text, string $name): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^' . preg_quote($name, '/') . '\{[^=]+="([^"]*)"\} (\d+)$/', $line, $m)) {
                $out[$m[1]] = (int) $m[2];
            }
        }
        return $out;
    }

    private function cluster(string $id, int $impact, array $kinds, array $tags): Cluster
    {
        $stmts   = (new AstParser())->parseCode('<?php $x;');
        $members = [];
        $i       = 0;
        foreach ($kinds as $kind) {
            $members[] = new Block(
                file: 'member-' . $i++ . '.php',
                range: new LineRange(1, 1),
                kind: $kind,
                namespace: null, class: null, name: null,
                ast: $stmts[0],
            );
        }
        $cluster            = new Cluster($id, $members, 1.0, false);
        $cluster->impact    = $impact;
        $cluster->patternTags = $tags;
        return $cluster;
    }

    /**
     * One-cluster report whose only label-bearing surfaces are the member
     * kind and the single pattern tag — the surviving hostile-input paths
     * since output-026 retired the id label.
     */
    private function syntheticReport(string $kind = 'method', string $tag = ''): Report
    {
        $stmts   = (new AstParser())->parseCode('<?php $x;');
        $members = [];
        foreach (['a.php', 'b.php'] as $f) {
            $members[] = new Block(
                file: $f,
                range: new LineRange(1, 1),
                kind: $kind,
                namespace: null, class: null, name: null,
                ast: $stmts[0],
            );
        }
        $cluster = new Cluster('synthetic-id', $members, 1.0, false);
        if ($tag !== '') {
            $cluster->patternTags = [$tag];
        }
        return new Report(
            files: 2, blocks: 2, parseErrors: 0,
            clusters: [$cluster],
            config: Config::defaults([]),
        );
    }

    private function buildReport(): Report
    {
        $config = new Config(
            paths: [__DIR__ . '/../../Fixtures/sql'],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
        );
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: true))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        return new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: (new Ranker($config->minClusterImpact))->rank($state->clusters),
            config: $config,
        );
    }
}
