<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Semantic;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstParser;
use Phpdup\Semantic\CallGraph;
use Phpdup\Semantic\ControlFlowGraph;

final class CallGraphTest extends TestCase
{
    public function testCallGraphCapturesMethodAndFunctionNames(): void
    {
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $stmts = $parser->parseCode('<?php function f($db) { $db->query("X"); $rows = fetch(); return $rows; }');
        $blocks = $extractor->extract('virtual.php', $stmts);
        $blocks[0]->id = 'B';

        $cg = new CallGraph();
        $cg->build($blocks);
        $sig = $cg->signatureFor('B');
        $this->assertNotNull($sig);
        $this->assertArrayHasKey('query', $sig);
        $this->assertArrayHasKey('fetch', $sig);
    }

    public function testControlFlowGraphCountsBranchesAndLoops(): void
    {
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $stmts = $parser->parseCode('<?php function f($items) { foreach ($items as $i) { if ($i > 0) { return $i; } } }');
        $blocks = $extractor->extract('virtual.php', $stmts);

        $cfg = (new ControlFlowGraph())->summarize($blocks[0]->ast);
        $this->assertSame(1, $cfg['loops']);
        $this->assertSame(1, $cfg['branches']);
        $this->assertSame(1, $cfg['returns']);
    }
}
