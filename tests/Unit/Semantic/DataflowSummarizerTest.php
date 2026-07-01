<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Semantic;

use PHPUnit\Framework\TestCase;
use Phpdup\Parsing\AstParser;
use Phpdup\Semantic\DataflowSummarizer;

final class DataflowSummarizerTest extends TestCase
{
    public function testSummarizeDoesNotFatal(): void
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode('<?php function compute($x, $y) { $sum = $x + $y; return $sum; }');
        $this->assertNotNull($stmts);
        $this->assertCount(1, $stmts);

        $summarizer = new DataflowSummarizer();
        $result = $summarizer->summarize($stmts[0]);

        $this->assertCount(4, $result);
        $this->assertArrayHasKey('vars', $result);
        $this->assertArrayHasKey('returns', $result);
        $this->assertArrayHasKey('calls', $result);
        $this->assertArrayHasKey('sideEffects', $result);
        $this->assertFalse($result['sideEffects']);
    }
}
