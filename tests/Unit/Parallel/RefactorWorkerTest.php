<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parallel;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parallel\RefactorWorker;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\RefactorStage;
use Symfony\Component\Console\Output\NullOutput;

final class RefactorWorkerTest extends TestCase
{
    public function testProcessReturnsEnrichmentForEachCluster(): void
    {
        $worker = new RefactorWorker(
            astLoader: null,
            optionalBlocksEnabled: true,
            optionalBlocksMaxPerCluster: 3,
            optionalBlocksMinSegmentLength: 1,
        );

        $cluster = $this->clusterFor('A', [
            'function f($x) { return $this->validate($x); }',
            'function f($x) { return $this->compile($x); }',
        ]);

        $result = $worker->process([$cluster]);

        $this->assertCount(1, $result);
        $row = $result[0];
        $this->assertSame('A', $row['id']);
        $this->assertNotNull($row['generalizedAst']);
        $this->assertNotEmpty($row['holes']);
        $this->assertNotNull($row['signature']);
        $this->assertContains('strategy', $row['patternTags']);
    }

    public function testProcessIsEquivalentToSerialPipeline(): void
    {
        $code = [
            'function notifyHigh($u, $s) { if ($s > 10) { send("admin", $u); } }',
            'function notifyMid($u, $s)  { if ($s > 20) { send("moderator", $u); } }',
        ];

        $serial = $this->clusterFor('SERIAL', $code);
        (new \Phpdup\Refactor\AntiUnifier())->unify($serial);
        (new \Phpdup\Refactor\ParameterSynthesizer())->synthesize($serial);
        (new \Phpdup\Refactor\SignatureBuilder())->buildSignature($serial);
        (new \Phpdup\Refactor\PatternRecognizer())->tag($serial);

        $parallel = $this->clusterFor('SERIAL', $code);
        $worker = new RefactorWorker(null, true, 3, 1);
        $rows = $worker->process([$parallel]);
        $this->assertCount(1, $rows);
        $this->assertSame($serial->signature, $rows[0]['signature']);
        $this->assertSame($serial->patternTags, $rows[0]['patternTags']);
        $this->assertSame(count($serial->holes), count($rows[0]['holes']));
    }

    /**
     * once-S102 acceptance (php-dup-finder-once-127, DET-06/DET-07):
     * the FULL RefactorStage run over an identical (freshly re-extracted)
     * cluster list — once serially (workers: 1, below the fork gate) and
     * once through real forked workers (workers: 2, pcntl-gated so this
     * arm demonstrably exercises iterParallel: the stage picks parallel
     * exactly when isAvailable() && workers > 1 && clusters >= 8) — must
     * merge back byte-identically: same cluster order, byte-identical
     * signatures, generalized ASTs, holes and hole paths, same pattern
     * tags, same refactored counter. This is the fork-vs-serial parity
     * pin the residual audit was funded for.
     */
    public function testStageForkVersusSerialMergeIsByteIdentical(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl fork pool unavailable on this host');
        }

        $spec = $this->eightClusterSpec();

        $serial = $this->runStageMerge($spec, 1);
        $parallel = $this->runStageMerge($spec, 2);

        // The fixture must have actually produced enrichments, or the
        // comparison below would be vacuously true over all-null output.
        $this->assertNotNull($serial['sigs'][0], 'fixture must mint real signatures');
        $this->assertNotSame('N;', $serial['asts'][0], 'fixture must mint a generalized AST');

        $this->assertSame($serial['ids'], $parallel['ids'], 'cluster order is merge-invariant');
        $this->assertSame($serial['sigs'], $parallel['sigs'], 'signatures are byte-identical across merge arms');
        $this->assertSame($serial['asts'], $parallel['asts'], 'generalized ASTs are byte-identical across merge arms');
        $this->assertSame($serial['holes'], $parallel['holes'], 'holes are byte-identical across merge arms');
        $this->assertSame($serial['paths'], $parallel['paths'], 'hole paths are byte-identical across merge arms');
        $this->assertSame($serial['tags'], $parallel['tags'], 'pattern tags are identical across merge arms');
        $this->assertSame($serial['done'], $parallel['done'], 'the refactored counter cannot diverge between arms');
        $this->assertSame(8, $parallel['done'], 'every cluster is counted exactly once');
    }

    /**
     * once-S102 (DET-06): two positions sharing ONE id (equal content —
     * ids are content-derived, so co-id clusters are twins, never
     * strangers) must BOTH end up enriched identically under either
     * merge arm. Before the broadcast fix the fork arm applied records
     * to a last-write-wins singleton and silently left the earlier twin
     * raw (null signature) while the serial loop enriched every twin
     * it walked past.
     */
    public function testStageMergeEnrichesEverySameIdTwinUnderBothArms(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl fork pool unavailable on this host');
        }

        // 8 distinct clusters + a 9th position that is a content twin of
        // K7 (same member code, same id, freshly extracted objects).
        $spec = $this->eightClusterSpec();
        $twinOf = $spec[7];
        $spec[] = [$twinOf[0], $twinOf[1]];

        $serial = $this->runStageMerge($spec, 1);
        $parallel = $this->runStageMerge($spec, 2);

        $this->assertSame($serial['ids'], $parallel['ids'], 'twin positions keep their slot in both arms');
        $this->assertSame($serial, $parallel, 'the twin state merges byte-identically across arms');

        // Explicit twin assertions (belt over the whole-run compare): both
        // K7 objects — position 7 and the appended twin — are enriched.
        $this->assertSame('K7', $serial['ids'][8]);
        $this->assertNotNull($parallel['sigs'][7], 'the canonical K7 is enriched under fork');
        $this->assertNotNull($parallel['sigs'][8], 'the later twin is enriched under fork');
        $this->assertSame($parallel['sigs'][7], $parallel['sigs'][8], 'co-id twins carry the same signature');
        $this->assertSame($parallel['asts'][7], $parallel['asts'][8], 'co-id twins carry the same generalized AST');
        $this->assertSame(9, $parallel['done'], 'twin records each count exactly once');
    }

    /**
     * @return list<array{0: string, 1: list<string>}> cluster id => member code
     */
    private function eightClusterSpec(): array
    {
        $spec = [];
        for ($i = 0; $i < 8; $i++) {
            $spec[] = ["K{$i}", [
                "function alpha{$i}_hi(\$u, \$s) { if (\$s > " . (10 + $i) . ") { send(\"root{$i}\", \$u); } }",
                "function alpha{$i}_lo(\$u, \$s) { if (\$s > " . (20 + $i) . ") { send(\"peer{$i}\", \$u); } }",
            ]];
        }
        return $spec;
    }

    /**
     * Full-stage merge run with fresh extraction per call, so the two
     * arms never share a mutated object graph.
     *
     * @param list<array{0: string, 1: list<string>}> $spec
     * @return array{ids: list<string>, sigs: list<?string>, asts: list<string>,
     *               holes: list<string>, paths: list<string>,
     *               tags: list<list<string>>, done: int}
     */
    private function runStageMerge(array $spec, int $workers): array
    {
        $config = new Config(
            paths: [],
            exclude: [],
            minBlockSize: 1,
            workers: $workers,
            lazyAst: false,
        );
        $state = new PipelineState($config);
        foreach ($spec as [$id, $functions]) {
            $state->clusters[] = $this->clusterFor($id, $functions);
        }

        (new RefactorStage(useCache: false))->run($state, new NullOutput());

        $out = [
            'ids' => [], 'sigs' => [], 'asts' => [],
            'holes' => [], 'paths' => [], 'tags' => [],
            'done' => $state->refactoredClusters,
        ];
        foreach ($state->clusters as $cluster) {
            $out['ids'][] = $cluster->id;
            $out['sigs'][] = $cluster->signature;
            $out['asts'][] = serialize($cluster->generalizedAst);
            $out['holes'][] = serialize($cluster->holes);
            $out['paths'][] = serialize($cluster->holePaths);
            $out['tags'][] = $cluster->patternTags;
        }
        return $out;
    }

    /** @param list<string> $functions */
    private function clusterFor(string $id, array $functions): Cluster
    {
        $parser = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = [];
        foreach ($functions as $i => $code) {
            $stmts = $parser->parseCode('<?php ' . $code);
            $extracted = $extractor->extract("test{$i}.php", $stmts);
            $this->assertNotEmpty($extracted);
            $blocks[] = $extracted[0];
        }
        return new Cluster($id, $blocks, 1.0, false);
    }
}
