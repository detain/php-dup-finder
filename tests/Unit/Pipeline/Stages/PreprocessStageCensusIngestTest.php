<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Census\IdiomRow;
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
 * Item 3.1 ingestion (MINE-D): the armed census pipeline, end to end at
 * the stage seam. The substrate (src/Census/*, MINE-C) proved the miner
 * and the accumulator in isolation; THESE tests pin the consumer legs the
 * substrate's CONTRACT FOR CONSUMERS demands:
 *
 *  1. demand-off inertness — without idiom_mining no census is ever
 *     constructed (rows cannot appear, the dispatch arm never fires);
 *  2. armed ingest — the 'idiom' flavor of the one 'mined' row stream
 *     lands in the run's SequenceIndex through armIdioms + recordIdiomFile
 *     (same dispatch, flavor-keyed, the stmtseq arm byte-unchanged; the
 *     census sees EVERY file: multi-file rows materialise locations);
 *  3. the warm asymmetry rule, obeyed not merely asserted — an armed run
 *     takes NO IndexStore: warm-reused files emit no mined rows, so a
 *     replay-backed armed census would be partial while looking complete.
 *     The armed run neither reads NOR writes caches, and its census is
 *     identical to the cold run's; the following unarmed run reuses fully
 *     (proof the armed run poisoned no snapshot);
 *  4. the idiomMining leg of indexStoreConfigKey as the prophylactic
 *     second belt (flip = one-time clean invalidation, fingerprintIdMode
 *     doctrine);
 *  5. the §5f carrier seam: ReportStage hands the census to the Report
 *     (state->report->idiomCensus), and JsonReporter renders the
 *     conditional trailing summary block ONLY on armed payloads —
 *     unarmed summaries stay exactly the frozen 19-key set.
 */
final class PreprocessStageCensusIngestTest extends TestCase
{
    private const SHARED_BODY = <<<'PHP'
        $slug = strtolower(trim($key));
        if ($slug === '') { $slug = 'anon'; }
        return $slug;
    PHP;

    /** @return array{0:string,1:string} [tmpDir, cacheDir] */
    private function corpus(): array
    {
        $dir = sys_get_temp_dir() . '/phpdup_census_ingest_' . uniqid('', true);
        mkdir($dir, 0o775, true);
        // Two unrelated classes whose methods share one exact statement
        // run: the census's target shape (same-hash support across files,
        // the miner reading each body as one mined list).
        file_put_contents($dir . '/alpha.php', "<?php\nclass Alpha {\n    public function left(string \$key): string {\n        "
            . str_replace("\n", "\n        ", self::SHARED_BODY) . "\n    }\n}\n");
        file_put_contents($dir . '/beta.php', "<?php\nclass Beta {\n    public function right(string \$key): string {\n        "
            . str_replace("\n", "\n        ", self::SHARED_BODY) . "\n    }\n}\n");
        return [$dir, $dir . '/cache'];
    }

    /**
     * @param list<string> $kinds
     * @return ($state with census populated when armed)
     */
    private function runArmed(string $dir, string $cacheDir, bool $armed, bool $useCache, bool $incremental = true): PipelineState
    {
        $config = new Config(
            paths: [$dir],
            exclude: [],
            allowedKinds: [BlockExtractor::FRAGMENT_KIND, 'method'],
            idiomMining: $armed,
            workers: 1,
            cacheDir: $cacheDir,
            incremental: $incremental,
        );
        $state = new PipelineState($config);
        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: $useCache))->run($state, new NullOutput());
        return $state;
    }

    public function testDemandOffConstructsNoCensus(): void
    {
        [$dir, $cache] = $this->corpus();
        try {
            $state = $this->runArmed($dir, $cache, armed: false, useCache: false);
            // The fragment roster still feeds the stmtseq leg (2.1), but
            // the idiom arm never fired: the index exists uninhabited,
            // and no census object was ever made.
            $this->assertNotNull($state->sequenceIndex, 'stmtseq demand was armed by the roster');
            $this->assertFalse($state->sequenceIndex->idiomsArmed(), 'idiom demand never fired unarmed');
            $this->assertNull($state->sequenceIndex->idiomCensus());
        } finally {
            $this->removeDirectory($dir);
        }
    }

    public function testArmedRunIngestsIdiomRowsIntoTheSequenceIndex(): void
    {
        [$dir, $cache] = $this->corpus();
        try {
            $state = $this->runArmed($dir, $cache, armed: true, useCache: false);
            $census = $state->sequenceIndex?->idiomCensus();
            $this->assertNotNull($census, 'the armed run must carry a census');

            // Both files' shared statement runs are mined; at least one
            // exact hash is supported by two sites in two files.
            $this->assertGreaterThanOrEqual(1, $census->hashCount());
            $this->assertGreaterThanOrEqual(2, $census->sitesTotal());

            $multi = null;
            foreach ($census->maximalRows() as $row) {
                if ($row->files === 2 && $row->sites >= 2) {
                    $multi = $row;
                    break;
                }
            }
            $this->assertNotNull(
                $multi,
                'the shared $slug = strtolower(trim($key)) run must surface as a cross-file idiom row',
            );
            // Two-pass memory policy: multi-site rows carry locations, one
            // per sighting site (both files). The shared body sits on the
            // SAME line number in alpha and beta, and canonical ordering
            // puts alpha first; which of the two shared runs (assign or
            // if-statement) surfaces as `multi` is the census's own
            // hash-asc tie-break, so only the shape is pinned here — the
            // equality of the two line numbers is the corpus-truth claim.
            $this->assertCount(2, $multi->locations);
            $this->assertMatchesRegularExpression('/alpha\.php:\d{1,2}$/', $multi->locations[0]);
            $this->assertMatchesRegularExpression('/beta\.php:\d{1,2}$/', $multi->locations[1]);
            $this->assertSame(
                substr($multi->locations[0], -1),
                substr($multi->locations[1], -1),
                'one shared body, one line — the census points at the same statement in both files',
            );
            // Nodes inside the doc window 6..40.
            $this->assertGreaterThanOrEqual(6, $multi->nodes);
            $this->assertLessThanOrEqual(40, $multi->nodes);
        } finally {
            $this->removeDirectory($dir);
        }
    }

    public function testWarmReplayIsBypassedWhileArmedAndUntouchedAfterwards(): void
    {
        [$dir, $cache] = $this->corpus();
        try {
            // Run 1: ordinary unarmed warm pass — snapshots everything.
            $first = $this->runArmed($dir, $cache, armed: false, useCache: true);
            $this->assertSame(0, $first->reusedFiles, 'run 1 is cold');
            $this->assertGreaterThan(0, $first->processedFiles);

            // Run 2: demand FLIPS on with the warm store in place. The
            // armed run must not reuse a single file — a warm-reused file
            // emits no mined rows, so a partial census served as complete
            // would lie about the corpus.
            $armed = $this->runArmed($dir, $cache, armed: true, useCache: true);
            $this->assertSame(0, $armed->reusedFiles, 'armed runs take no IndexStore');
            $this->assertNotNull($armed->sequenceIndex?->idiomCensus(), 'and their census is corpus-complete');

            // And it equals the cold armed census, row for row — reuse
            // neither adds nor drops mined truth.
            $cold = $this->runArmed($dir, $cache, armed: true, useCache: false);
            $rowsA = array_map(
                static fn (IdiomRow $r): string => $r->exactHash . ':' . $r->sites . ':' . $r->files,
                $armed->sequenceIndex->idiomCensus()->maximalRows(),
            );
            $rowsB = array_map(
                static fn (IdiomRow $r): string => $r->exactHash . ':' . $r->sites . ':' . $r->files,
                $cold->sequenceIndex->idiomCensus()->maximalRows(),
            );
            $this->assertSame($rowsB, $rowsA, 'armed warm bypass replays exactly the cold census');

            // Run 3: flip back to unarmed — run 2 neither wrote nor read,
            // so run 1's snapshots are intact and serve again.
            $back = $this->runArmed($dir, $cache, armed: false, useCache: true);
            $this->assertGreaterThan(0, $back->reusedFiles, 'armed runs leave the unarmed namespace pristine');
            $this->assertNull($back->sequenceIndex?->idiomCensus(), 'unarmed carries no census');
        } finally {
            $this->removeDirectory($dir);
        }
    }

    public function testCensusDemandBindsTheIndexStoreKeyAsProphylacticSecondBelt(): void
    {
        $method = new \ReflectionMethod(PreprocessStage::class, 'indexStoreConfigKey');
        $stage  = new PreprocessStage(useCache: false);
        $base   = ['paths' => ['/tmp/whatever'], 'exclude' => [], 'allowedKinds' => [BlockExtractor::FRAGMENT_KIND, 'method']];

        $unarmed = new Config(...$base);
        $armed   = new Config(...$base, idiomMining: true);

        $this->assertNotSame(
            $method->invoke($stage, $armed),
            $method->invoke($stage, $unarmed),
            'flipping idiom_mining must invalidate every existing .idx tree once (fingerprintIdMode precedent)',
        );
    }

    public function testReportCarrierAndConditionalSummaryBlockEndToEnd(): void
    {
        [$dir, $cache] = $this->corpus();
        try {
            $state = $this->runArmed($dir, $cache, armed: true, useCache: false);
            (new ReportStage(limit: 50, showStats: false))->run($state, new NullOutput());
            $this->assertNotNull($state->report);
            $this->assertSame(
                $state->sequenceIndex?->idiomCensus(),
                $state->report->idiomCensus,
                'the census crosses the state→Report seam whole',
            );

            $payload  = (new JsonReporter())->build($state->report);
            $unarmed  = JsonSchemaSpec::summaryShape(false);
            $armedKey = array_keys($payload['summary']);
            $this->assertSame(
                array_merge(array_keys($unarmed), ['idiom_census']),
                $armedKey,
                'armed summary = the frozen key set plus the conditional trailing block',
            );
            $this->assertSame(array_keys(JsonSchemaSpec::summaryShape(true)), $armedKey);
            $block = $payload['summary']['idiom_census'];
            $this->assertSame(
                array_keys(JsonSchemaSpec::censusBlockShape()),
                array_keys($block),
            );
            $this->assertIsInt($block['hashes']);
            $this->assertIsInt($block['sites']);
            $this->assertNotEmpty($block['rows']);
            $this->assertSame(
                ['hash', 'abstract', 'nodes', 'root', 'sites', 'files', 'locations'],
                array_keys($block['rows'][0]),
            );

            // The very same corpus unarmed: the census key must be ABSENT
            // — never null, never empty — or the frozen goldens would
            // have moved (they cannot; see the byte-frozen gate).
            $state2 = $this->runArmed($dir, $cache, armed: false, useCache: false);
            (new ReportStage(limit: 50, showStats: false))->run($state2, new NullOutput());
            $payload2 = (new JsonReporter())->build($state2->report);
            $this->assertArrayNotHasKey('idiom_census', $payload2['summary']);
            $this->assertNull($state2->report->idiomCensus);
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
