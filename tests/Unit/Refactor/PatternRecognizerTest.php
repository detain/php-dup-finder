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

    public function testControllerActionTagFromClassNameSuffix(): void
    {
        $blocks = $this->dummyMembers('show');
        foreach ($blocks as $b) {
            $b->class = 'PostController';
        }
        $cluster = new Cluster('TEST', $blocks, 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('controller-action', $cluster->patternTags);
    }

    public function testControllerActionTagFromFilePath(): void
    {
        $blocks = $this->dummyMembers('show');
        foreach ($blocks as $b) {
            $b->file = '/var/www/app/Http/Controllers/PostsController.php';
        }
        $cluster = new Cluster('TEST', $blocks, 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('controller-action', $cluster->patternTags);
    }

    public function testMigrationTagFromPathAndMemberName(): void
    {
        $blocks = $this->dummyMembers('up');
        foreach ($blocks as $b) {
            $b->file = '/app/database/migrations/2024_01_01_000000_create_users.php';
        }
        $cluster = new Cluster('TEST', $blocks, 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('migration', $cluster->patternTags);
    }

    public function testRepositoryMethodTag(): void
    {
        $blocks = $this->dummyMembers('findById');
        foreach ($blocks as $b) {
            $b->class = 'UserRepository';
        }
        $cluster = new Cluster('TEST', $blocks, 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('repository-method', $cluster->patternTags);
    }

    public function testEventListenerTag(): void
    {
        $blocks = $this->dummyMembers('handle');
        foreach ($blocks as $b) {
            $b->class = 'OrderShippedListener';
        }
        $cluster = new Cluster('TEST', $blocks, 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('event-listener', $cluster->patternTags);
    }

    public function testServiceProviderTag(): void
    {
        $blocks = $this->dummyMembers('register');
        foreach ($blocks as $b) {
            $b->class = 'AppServiceProvider';
        }
        $cluster = new Cluster('TEST', $blocks, 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('service-provider', $cluster->patternTags);
    }

    public function testEloquentModelTagFromNamespace(): void
    {
        $blocks = $this->dummyMembers('scopeActive');
        foreach ($blocks as $b) {
            $b->namespace = 'App\\Models';
            $b->class     = 'User';
        }
        $cluster = new Cluster('TEST', $blocks, 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('eloquent-model', $cluster->patternTags);
    }

    public function testLoopMapTag(): void
    {
        $blocks = $this->blocksFromCode('<?php function f($items) { $out = []; foreach ($items as $i) { $out[] = strtoupper($i); } return $out; }');
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('loop-map', $cluster->patternTags);
    }

    public function testLoopFilterTag(): void
    {
        $blocks = $this->blocksFromCode('<?php function f($items) { foreach ($items as $i) { if (!$i->valid()) continue; do_thing($i); } }');
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('loop-filter', $cluster->patternTags);
    }

    public function testSqlQueryTag(): void
    {
        $blocks = $this->blocksFromCode('<?php function f($db) { return $db->query("SELECT * FROM users WHERE id = 1"); }');
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('sql-query', $cluster->patternTags);
    }

    public function testHttpCallTag(): void
    {
        $blocks = $this->blocksFromCode('<?php function fetch($http) { return $http->get("https://api.example.com/users", []); }');
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('http-call', $cluster->patternTags);
    }

    public function testErrorHandlerTag(): void
    {
        $blocks = $this->blocksFromCode('<?php function f() { try { do_thing(); } catch (\\Throwable $e) { $logger->error($e); } }');
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('error-handler', $cluster->patternTags);
    }

    public function testBuilderChainTag(): void
    {
        $blocks = $this->blocksFromCode('<?php function build() { return $b->setA(1)->setB(2)->setC(3)->build(); }');
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('builder-chain', $cluster->patternTags);
    }

    public function testContainerRegistrationTag(): void
    {
        $blocks = $this->blocksFromCode('<?php function register() { $this->bind(Foo::class, FooImpl::class); $this->bind(Bar::class, BarImpl::class); }');
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('container-registration', $cluster->patternTags);
    }

    /** @return list<\Phpdup\Extraction\Block> */
    private function blocksFromCode(string $code): array
    {
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $stmts = $parser->parseCode($code);
        $blocks = $extractor->extract('virtual.php', $stmts);
        $this->assertNotEmpty($blocks);
        return $blocks;
    }

    public function testQueryBuilderChainTagFromCallName(): void
    {
        // Build a block whose AST contains a `createQueryBuilder()` call so the
        // node-finder path picks it up.
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $stmts = $parser->parseCode('<?php function findActive() { return $this->em->createQueryBuilder()->select("u")->from(User::class, "u")->getQuery()->getResult(); }');
        $blocks = $extractor->extract('virtual.php', $stmts);
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('query-builder-chain', $cluster->patternTags);
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
