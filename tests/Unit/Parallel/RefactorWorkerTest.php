<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parallel;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parallel\RefactorWorker;
use Phpdup\Parsing\AstParser;

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
