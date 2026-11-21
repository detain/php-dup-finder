<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
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

    // ---------------------------------------------------------------
    // output-021: tag honesty — evidence required, fail closed
    // ---------------------------------------------------------------

    public function testNullAstMemberReceivesNoSemanticTags(): void
    {
        // The lazy-AST case used to auto-yes container-registration from
        // the method name alone (and that is the only detector family
        // with a null-AST return-true path). Any AST-based tag on an
        // unloaded member is a lie; name-based heuristics may still fire.
        $blocks = $this->dummyMembers('register');
        foreach ($blocks as $b) {
            $b->ast = null;
        }
        $cluster = new Cluster('TEST', $blocks, 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertSame([], $cluster->patternTags);
    }

    public function testCrudHandlerRejectsSubstringVerbNames(): void
    {
        // thread() ⊃ "read", preselect() ⊃ "select", prefetch() ⊃ "fetch"
        // — bare substring matching tagged all three as crud handlers.
        $thread  = $this->blocksFromCode('<?php function thread() { return 1; }');
        $pre     = $this->blocksFromCode('<?php function preselect() { return 2; }');
        $pref    = $this->blocksFromCode('<?php function prefetch() { return 3; }');
        $cluster = new Cluster('TEST', [$thread[0], $pre[0], $pref[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertNotContains('crud-handler', $cluster->patternTags);
    }

    public function testCrudHandlerKeepsWholeSegmentVerbs(): void
    {
        foreach (['deleteUser', 'insert_row', 'Create', 'fetchMany'] as $name) {
            $blocks  = $this->blocksFromCode("<?php function {$name}() { return 1; }");
            $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
            (new PatternRecognizer())->tag($cluster);
            $this->assertContains('crud-handler', $cluster->patternTags,
                "segment-level CRUD verb '{$name}' must still tag");
        }
    }

    public function testEnglishProseNotTaggedSqlBuilder(): void
    {
        // "loaded from cache" carries the word FROM but no SQL statement
        // shape — the old anywhere-keyword arm tagged it sql-builder.
        $blocks  = $this->blocksFromCode('<?php function f($cache) { $m = "loaded from cache"; $cache->store($m); return $m; }');
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertNotContains('sql-builder', $cluster->patternTags);
    }

    public function testSwitchWithNonUniformArmsNotTaggedStateMachine(): void
    {
        $blocks  = $this->blocksFromCode('<?php function h($s) { switch ($s) { case "a": doA(); doB(); break; case "b": exit(1); } }');
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertNotContains('state-machine', $cluster->patternTags);
    }

    public function testSwitchWithDifferingCallArmsNotTaggedStateMachine(): void
    {
        // Uniform statement count, different methods → different shape.
        $blocks  = $this->blocksFromCode('<?php function h($s) { switch ($s) { case 1: sendA(); break; case 2: sendB(); break; } }');
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertNotContains('state-machine', $cluster->patternTags);
    }

    public function testSwitchWithNonLiteralArmsNotTaggedStateMachine(): void
    {
        // Arms conditioned on call results, not state literals.
        $blocks  = $this->blocksFromCode('<?php function h($s) { switch ($s) { case checkA(): one(); break; case checkB(): one(); break; } }');
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertNotContains('state-machine', $cluster->patternTags);
    }

    public function testUrlOnlyConfigBodyNotTaggedHttpCall(): void
    {
        // Example URL sitting in a config body while the only candidate
        // call (file_get_contents) reads a local path: the URL was never
        // an argument of the call, so this is not HTTP (output-021).
        $blocks  = $this->blocksFromCode('<?php function cfg() { $api = "https://api.example.com/v1"; return [$api, file_get_contents("/etc/defaults")]; }');
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertNotContains('http-call', $cluster->patternTags);
    }

    public function testHttpCallRejectsVariableUrlArgument(): void
    {
        // $client->get($url) — verb call present, but the URL literal is
        // assigned elsewhere, not passed as the call's argument.
        $blocks  = $this->blocksFromCode('<?php function f($client) { $url = "https://example.com"; return $client->get($url); }');
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertNotContains('http-call', $cluster->patternTags);
    }

    public function testHeaderDocblockMatchesTagEmissionInventory(): void
    {
        $ref  = new \ReflectionClass(PatternRecognizer::class);
        $doc  = (string)$ref->getDocComment();
        $tag  = $ref->getMethod('tag');
        $src  = implode('', array_slice(
            (array)file($ref->getFileName()),
            $tag->getStartLine() - 1,
            $tag->getEndLine() - $tag->getStartLine() + 1
        ));

        $this->assertNotFalse(preg_match_all("/tags\\[\\] = '([a-z][a-z-]*)'/", $src, $emitted));
        $this->assertNotEmpty($emitted[1]);
        // Inventory lines are " *   tag          — description"; fixed
        // 3-space indent keeps prose and continuation lines out.
        preg_match_all('/^ \*   ([a-z][a-z-]+) +—/m', $doc, $documented);

        $this->assertEqualsCanonicalizing(
            $emitted[1],
            $documented[1],
            'header docblock inventory must equal the tags tag() emits'
        );
    }

    // ---------------------------------------------------------------
    // output-064: per-tag detection scenarios (per CLAUDE.md mandate)
    // ---------------------------------------------------------------

    public function testSqlBuilderTagFromConcatAndQueryChain(): void
    {
        // The archetype: concatenated SQL feeding prepare/execute/fetch.
        $blocks = $this->blocksFromCode(
            '<?php function byName($db, $n) { $sql = "SELECT id FROM users WHERE name = " . $n; $stmt = $db->prepare($sql); $stmt->execute(); return $stmt->fetch(); }'
        );
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('sql-builder', $cluster->patternTags);
    }

    public function testSqlBuilderTagFromUppercaseSqlLiteral(): void
    {
        // No concat here — the anchored literal arm must carry it.
        $blocks = $this->blocksFromCode(
            '<?php function all($conn) { return $conn->queryAll("SELECT * FROM reports WHERE enabled = 1"); }'
        );
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('sql-builder', $cluster->patternTags);
    }

    public function testStateMachineTagFromUniformStateSwitch(): void
    {
        // Switch on a state whose arms share one body shape, varying
        // only by the state literals (the documented promise).
        $blocks = $this->blocksFromCode(
            '<?php function step($state) { switch ($state) { case "idle": $this->go("running"); break; case "running": $this->go("idle"); break; } }'
        );
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('state-machine', $cluster->patternTags);
    }

    public function testStateMachineTagFromConstMapSwitch(): void
    {
        // Class-constant state map; the default arm may differ in shape
        // (a catch-all is not a state).
        $blocks = $this->blocksFromCode(
            '<?php function step($s) { switch ($s) { case self::IDLE: return "wait"; case self::RUNNING: return "go"; default: return "bad"; } }'
        );
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('state-machine', $cluster->patternTags);
    }

    public function testStateMachineTagFromUniformMatchArms(): void
    {
        $blocks = $this->blocksFromCode(
            '<?php function rank($s) { return match ($s) { "idle" => 1, "running" => 2, default => 0 }; }'
        );
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('state-machine', $cluster->patternTags);
    }

    public function testQueryBuilderChainTag(): void
    {
        // Per-tag scenario for the query-builder entrypoint detector.
        $blocks = $this->blocksFromCode(
            '<?php function listAll($em) { $qb = $em->createQueryBuilder(); return $qb->select("u")->from("User", "u")->getQuery(); }'
        );
        $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('query-builder-chain', $cluster->patternTags);
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

    public function testDbOpTagAddedWhenClusterContainsDbReadCall(): void
    {
        $block = $this->blockWithDbCall('__DB_READ__', 'users');
        $cluster = new Cluster('TEST', [$block, $block], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('db-op', $cluster->patternTags);
    }

    public function testDbOpTagAddedWhenClusterContainsDbWriteCall(): void
    {
        $block = $this->blockWithDbCall('__DB_WRITE__', 'users');
        $cluster = new Cluster('TEST', [$block, $block], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('db-op', $cluster->patternTags);
    }

    public function testDbOpTagAddedWhenClusterContainsDbUpsertCall(): void
    {
        $block = $this->blockWithDbCall('__DB_UPSERT__', 'users');
        $cluster = new Cluster('TEST', [$block, $block], 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertContains('db-op', $cluster->patternTags);
    }

    public function testDbOpTagAbsentWhenClusterHasNoDbCalls(): void
    {
        $cluster = new Cluster('TEST', $this->dummyMembers(), 1.0, false);
        (new PatternRecognizer())->tag($cluster);
        $this->assertNotContains('db-op', $cluster->patternTags);
    }

    private function blockWithDbCall(string $funcName, string $table): Block
    {
        $syntheticCall = new FuncCall(
            new Name($funcName),
            [new Arg(new String_($table))]
        );
        $syntheticCall->setAttribute('phpdup.dbOp', 'db.' . strtolower(substr($funcName, 5, -2)));

        $function = new Node\Stmt\Function_('testFunc', [
            'stmts' => [new Node\Stmt\Return_($syntheticCall)],
        ]);

        $block = new Block(
            file: 'test.php',
            range: new LineRange(1, 3),
            kind: 'function',
            namespace: null,
            class: null,
            name: 'testFunc',
            ast: $function,
        );
        $block->canonical = $function;

        return $block;
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
