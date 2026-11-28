<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\JsonSchemaSpec;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Items 3.5/3.3 at the stage seam (MINE-E), mirroring the 3.1 ingestion
 * doctrine: demand-off inertness; armed ingest of the 'calls' and
 * 'literal' flavors through the ONE mined-row dispatch; the store-bypass
 * extended to the literal demand (warm files emit no census rows); the
 * cache-key prophylactic belts; and the §5f carrier + conditional
 * summary blocks end to end (OFF keeps the frozen key set byte-exact).
 */
final class PreprocessStageCallLiteralTest extends TestCase
{
    private const SHARED_BODY = <<<'PHP'
        $safe = strtolower(trim($key));
        $meta = json_encode(['source' => 'ingest-stage', 'mode' => 'strict', 'tag' => 'shared_literal_string']);
        if ($safe === '') { $safe = 'anonymous_placeholder'; }
        return strtoupper($safe);
    PHP;

    /** @return array{0:string,1:string} [tmpDir, cacheDir] */
    private function corpus(): array
    {
        $dir = sys_get_temp_dir() . '/phpdup_call_literal_' . uniqid('', true);
        mkdir($dir, 0o775, true);
        foreach (['alpha' => 'left', 'beta' => 'right'] as $file => $method) {
            file_put_contents("$dir/$file.php", "<?php\nclass " . ucfirst($file) . " {\n    public function $method(string \$key): string {\n        "
                . str_replace("\n", "\n        ", self::SHARED_BODY) . "\n    }\n}\n");
        }
        return [$dir, $dir . '/cache'];
    }

    private function runArmed(
        string $dir,
        string $cacheDir,
        bool $call,
        bool $literal,
        bool $useCache,
        bool $incremental = true,
    ): PipelineState {
        $config = new Config(
            paths: [$dir],
            exclude: [],
            allowedKinds: [BlockExtractor::FRAGMENT_KIND, 'method'],
            idiomMining: $call, // the 3.5 door requires the idiom leg; mint its lists too
            callSequence: $call,
            literalCensus: $literal,
            workers: 1,
            cacheDir: $cacheDir,
            incremental: $incremental,
        );
        $state = new PipelineState($config);
        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: $useCache))->run($state, new NullOutput());
        return $state;
    }

    public function testDemandOffConstructsNeitherCensus(): void
    {
        [$dir, $cache] = $this->corpus();
        try {
            $state = $this->runArmed($dir, $cache, call: false, literal: false, useCache: false);
            $index = $state->sequenceIndex;
            $this->assertNotNull($index, 'the fragment roster still feeds the stmtseq leg');
            $this->assertFalse($index->callsArmed());
            $this->assertFalse($index->literalsArmed());
            $this->assertNull($index->callCensus());
            $this->assertNull($index->literalCensus());
        } finally {
            $this->removeDirectory($dir);
        }
    }

    public function testArmedRunIngestsBothFlavors(): void
    {
        [$dir, $cache] = $this->corpus();
        try {
            $state = $this->runArmed($dir, $cache, call: true, literal: true, useCache: false);

            $calls = $state->sequenceIndex?->callCensus();
            $this->assertNotNull($calls, 'the armed run must carry the call census');
            $this->assertSame(2, $calls->sitesOf("strtolower\0trim"), 'the shared call pair is one window, twice');
            $this->assertSame(2, $calls->filesOf("strtolower\0trim"));
            $row = null;
            foreach ($calls->maximalRows() as $candidate) {
                if ($candidate->names === ['strtolower', 'trim', 'json_encode', 'strtoupper']) {
                    $row = $candidate;
                    break;
                }
            }
            $this->assertNotNull($row, 'the shared four-call chain surfaces as the maximal row (the infix 2-window is lawfully suppressed beneath it)');
            $this->assertSame(2, $row->sites);
            $this->assertCount(2, $row->locations);

            $literals = $state->sequenceIndex?->literalCensus();
            $this->assertNotNull($literals);
            $byKind = [];
            foreach ($literals->rows() as $entry) {
                $byKind[$entry->kind][$entry->sample] = $entry;
            }
            $this->assertArrayHasKey('shared_literal_string', $byKind['string'] ?? [],
                'the ≥12-char shared literal surfaces as a row');
            $this->assertSame(2, $byKind['string']['shared_literal_string']->sites);
            $this->assertSame(2, $byKind['string']['shared_literal_string']->files);
            $this->assertCount(1, $byKind['array'] ?? [], 'the 3-entry option table hashes order-insensitively once');
            $this->assertSame(2, reset($byKind['array'])->sites);
        } finally {
            $this->removeDirectory($dir);
        }
    }

    public function testLiteralDemandBypassesWarmStoreAndLeavesItPristine(): void
    {
        [$dir, $cache] = $this->corpus();
        try {
            $first = $this->runArmed($dir, $cache, call: false, literal: false, useCache: true);
            $this->assertSame(0, $first->reusedFiles, 'run 1 is cold');
            $this->assertGreaterThan(0, $first->processedFiles);

            // Demand flips on with the warm store in place: literal rows
            // mint nothing warm-reusable, so the armed run must re-read
            // EVERY file rather than serve a partial census as complete.
            $armed = $this->runArmed($dir, $cache, call: false, literal: true, useCache: true);
            $this->assertSame(0, $armed->reusedFiles, 'literal-armed runs take no IndexStore');
            $cold = $this->runArmed($dir, $cache, call: false, literal: true, useCache: false);
            $render = static fn (?\Phpdup\Census\LiteralCensus $c): string => json_encode(
                $c?->rows() === null ? null
                : array_map(static fn ($r) => [$r->kind, $r->hash, $r->sites, $r->files], $c->rows()),
            );
            $this->assertSame($render($cold->sequenceIndex?->literalCensus()),
                $render($armed->sequenceIndex?->literalCensus()),
                'armed warm bypass replays exactly the cold census');

            $back = $this->runArmed($dir, $cache, call: false, literal: false, useCache: true);
            $this->assertGreaterThan(0, $back->reusedFiles, 'the armed run poisoned no snapshot');
        } finally {
            $this->removeDirectory($dir);
        }
    }

    public function testBothDemandsBindTheIndexStoreKeyAsSecondBelt(): void
    {
        $method = new \ReflectionMethod(PreprocessStage::class, 'indexStoreConfigKey');
        $stage  = new PreprocessStage(useCache: false);
        $base   = ['paths' => ['/tmp/whatever'], 'exclude' => [], 'allowedKinds' => [BlockExtractor::FRAGMENT_KIND, 'method']];

        $plain      = new Config(...$base);
        $idiom      = new Config(...$base, idiomMining: true);
        $calls      = new Config(...$base, idiomMining: true, callSequence: true);
        $literals   = new Config(...$base, literalCensus: true);

        $this->assertNotSame($method->invoke($stage, $calls), $method->invoke($stage, $idiom),
            'flipping call_sequence must invalidate every .idx tree once');
        $this->assertNotSame($method->invoke($stage, $literals), $method->invoke($stage, $plain),
            'flipping literal_census must invalidate every .idx tree once');
    }

    public function testReportCarrierAndConditionalSummaryBlocksEndToEnd(): void
    {
        [$dir, $cache] = $this->corpus();
        try {
            $state = $this->runArmed($dir, $cache, call: true, literal: true, useCache: false);
            (new ReportStage(limit: 50, showStats: false))->run($state, new NullOutput());
            $this->assertNotNull($state->report);
            $this->assertSame($state->sequenceIndex?->callCensus(), $state->report->callSequences,
                'both censuses cross the state→Report seam whole');
            $this->assertSame($state->sequenceIndex?->literalCensus(), $state->report->literalCensus);

            $payload  = (new JsonReporter())->build($state->report);
            $armedKey = array_keys($payload['summary']);
            $this->assertSame(array_keys(JsonSchemaSpec::summaryShape(armed: true, callsArmed: true, literalsArmed: true)), $armedKey,
                'armed summary = frozen keys plus the three conditional trailing blocks in schema order');
            $this->assertSame(array_keys(JsonSchemaSpec::callBlockShape()), array_keys($payload['summary']['call_sequences']));
            $this->assertSame(array_keys(JsonSchemaSpec::literalBlockShape()), array_keys($payload['summary']['literal_census']));
            $this->assertNotEmpty($payload['summary']['call_sequences']['rows']);
            $this->assertNotEmpty($payload['summary']['literal_census']['rows']);

            $state2 = $this->runArmed($dir, $cache, call: false, literal: false, useCache: false);
            (new ReportStage(limit: 50, showStats: false))->run($state2, new NullOutput());
            $payload2 = (new JsonReporter())->build($state2->report);
            $this->assertArrayNotHasKey('call_sequences', $payload2['summary']);
            $this->assertArrayNotHasKey('literal_census', $payload2['summary']);
            $this->assertSame(array_keys(JsonSchemaSpec::summaryShape(false)), array_keys($payload2['summary']));
        } finally {
            $this->removeDirectory($dir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
