<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PhpParser\Node\Stmt\Function_;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\PatternRecognizer;
use Phpdup\Util\LineRange;

final class PatternRecognizerTest extends TestCase
{
    public function testOptionalSegmentsTagAddedWhenAnyOptionalBlockHole(): void
    {
        $cluster = new Cluster('TEST', $this->dummyMembers(), 1.0, false);
        $cluster->holes = [
            new Hole('__O0', 'optional_block', ['some_call();', '<absent>']),
        ];

        (new PatternRecognizer())->tag($cluster);

        $this->assertContains('optional-segments', $cluster->patternTags);
    }

    public function testOptionalSegmentsTagAbsentWhenNoOptionalBlockHole(): void
    {
        $cluster = new Cluster('TEST', $this->dummyMembers(), 1.0, false);
        $cluster->holes = [
            new Hole('__P0', 'literal', ['10', '20']),
        ];

        (new PatternRecognizer())->tag($cluster);

        $this->assertNotContains('optional-segments', $cluster->patternTags);
    }

    public function testConfigDrivenTagWhenAllHolesAreLiterals(): void
    {
        $cluster = new Cluster('TEST', $this->dummyMembers(), 1.0, false);
        $cluster->holes = [
            new Hole('__P0', 'literal', ['10', '20']),
            new Hole('__P1', 'literal', ["'a'", "'b'"]),
        ];

        (new PatternRecognizer())->tag($cluster);

        $this->assertContains('config-driven', $cluster->patternTags);
    }

    public function testStrategyTagWhenSingleNameHole(): void
    {
        $cluster = new Cluster('TEST', $this->dummyMembers(), 1.0, false);
        $cluster->holes = [
            new Hole('__P0', 'name', ['validate', 'compile']),
        ];

        (new PatternRecognizer())->tag($cluster);

        $this->assertContains('strategy', $cluster->patternTags);
    }

    public function testCrudHandlerDetectedFromMemberName(): void
    {
        $cluster = new Cluster('TEST', $this->dummyMembers('findById'), 1.0, false);

        (new PatternRecognizer())->tag($cluster);

        $this->assertContains('crud-handler', $cluster->patternTags);
    }

    /** @return list<Block> */
    private function dummyMembers(string $name = 'someMethod'): array
    {
        // Build two real Block instances with parsed ASTs so PatternRecognizer's
        // NodeFinder-based checks (sql-builder, validation-chain, etc.) can run
        // safely without nulling out.
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $stmts     = $parser->parseCode("<?php function {$name}() { return 1; }");
        $blocks    = $extractor->extract('virtual.php', $stmts);
        $this->assertNotEmpty($blocks);
        return [$blocks[0], $blocks[0]];
    }
}
