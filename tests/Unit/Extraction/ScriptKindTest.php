<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Parallel\PairScoreWorker;
use Phpdup\Refactor\ApplyExtractor;
use Phpdup\Refactor\ScriptKindApplyRefusal;
use Phpdup\Reporting\RefactorPatchReporter;
use Phpdup\Reporting\Report;

/**
 * Item 1.3 — the 'script' kind: opt-in extraction of top-level statement
 * folds, and the typed refusals that keep the AST-addressing machinery
 * from silently mis-anchoring on them.
 *
 * Law (codified in ScriptKindApplyRefusal): a script block is a SYNTHETIC
 * fold — the parse tree has no node for it. Therefore BlockAstLoader can
 * never relocate one (resolve + rebase refuse typed-loud, the loader's
 * matchesKind never positionally matches 'script'), and --apply /
 * --patch / ApplyExtractor refuse script clusters before touching disk.
 * The implicit "all kinds" set must never include script — default runs
 * (and every golden) see zero script blocks.
 */
final class ScriptKindTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sk-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
    }

    /** @param list<string> $allowedKinds @return list<Block> */
    private function extract(string $code, array $allowedKinds, int $minSize = 8, int $maxSize = 800, bool $windowing = true): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code) ?? [];
        return (new BlockExtractor(minSize: $minSize, maxSize: $maxSize, allowedKinds: $allowedKinds, windowOversized: $windowing))
            ->extract('virtual.php', $ast);
    }

    private function scriptBlock(string $code = "<?php\nif (\$a) { echo 1; }\n\$b = require 'x.php';\nforeach (\$c as \$d) { use_it(\$d); }\n"): Block
    {
        $blocks = $this->extract($code, ['script'], minSize: 1);
        $this->assertNotEmpty($blocks, 'fixture must yield a script fold');
        return $blocks[0];
    }

    // ── Extraction: opt-in posture ──────────────────────────────────────

    public function testScriptNeverInImplicitAllSet(): void
    {
        $code = "<?php\n\$cfg = load();\nif (\$cfg) { boot(\$cfg); }\nforeach (\$cfg as \$k => \$v) { define(\$k, \$v); }\necho \$cfg['x'] . PHP_EOL;\nrequire_once 'hooks.php';\nrun_the_thing(\$cfg);\n\$late = 1;\nif (\$late) { finish(); }\n";
        $kinds = array_map(static fn (Block $b): string => $b->kind, $this->extract($code, [], minSize: 1));
        $this->assertNotContains('script', $kinds, 'empty allow-list means all TRAVERSABLE kinds — folds stay opt-in');
    }

    public function testExplicitScriptEmitsOneFold(): void
    {
        $code = "<?php\n\$cfg = load();\nif (\$cfg) { boot(\$cfg); }\nforeach (\$cfg as \$k => \$v) { define(\$k, \$v); }\nfunction declared(): void {}\nclass Guard { public function ok(): bool { return true; } }\necho 'done' . PHP_EOL;\n";
        $blocks = $this->extract($code, ['script'], minSize: 4);
        $scripts = array_values(array_filter($blocks, static fn (Block $b): bool => $b->kind === 'script'));
        $this->assertCount(1, $scripts, 'contiguous declarative-free top level folds to one block');
        $fold = $scripts[0];
        $this->assertNull($fold->name);
        $this->assertNull($fold->class);
        $this->assertNull($fold->namespace);
        $this->assertTrue($fold->ast instanceof Stmt\Block);
        $this->assertSame(2, $fold->range->start, 'range starts at the first folded statement');
        $this->assertSame(7, $fold->range->end, 'range ends at the LAST folded statement (echo), not the file');
    }

    public function testDeclarationsAndBoilerplateStayOutOfFolds(): void
    {
        $code = "<?php\ndeclare(strict_types=1);\nuse Foo\\Bar;\nclass Guard { public function ok(): bool { return true; } }\nfunction helper(): void {}\nconst LIMIT = 5;\n\$x = compute();\nif (\$x) { go(\$x); }\n";
        $blocks = $this->extract($code, ['script'], minSize: 1);
        $this->assertCount(1, $blocks);
        $stmts = $blocks[0]->ast->stmts ?? [];
        foreach ($stmts as $s) {
            $this->assertFalse($s instanceof Stmt\ClassLike);
            $this->assertFalse($s instanceof Stmt\Function_);
            $this->assertFalse($s instanceof Stmt\Const_);
            $this->assertFalse($s instanceof Stmt\Use_);
            $this->assertFalse($s instanceof Stmt\Declare_);
        }
        $this->assertCount(2, $stmts, 'only the two logic statements fold');
    }

    public function testNamespacesNeverMixIntoOneFold(): void
    {
        $code = "<?php\nnamespace Alpha {\n    \$a = one();\n    if (\$a) { two(\$a); }\n    three();\n}\nnamespace Beta {\n    \$b = four();\n    five(\$b);\n    six();\n}\n";
        $blocks = $this->extract($code, ['script'], minSize: 1);
        $this->assertCount(2, $blocks, 'two bracketed namespaces → two scoped folds, never one');
        $this->assertSame('Alpha', $blocks[0]->namespace);
        $this->assertSame('Beta', $blocks[1]->namespace);
    }

    public function testFoldHonoursMinAndMaxSizeGates(): void
    {
        $small = "<?php\necho 1;\n";
        $this->assertCount(0, $this->extract($small, ['script'], minSize: 8), 'tiny folds stay silent');

        $bigCode = "<?php\n";
        for ($i = 0; $i < 700; $i++) {
            $bigCode .= "\$v{$i} = compute({$i});\n";
        }
        // Item 1.2 changed the maxSize leg's DIET, not the ceiling law:
        // the legacy posture (windowing off) still refuses to smuggle
        // the fold past the cap — zero blocks; the shipped posture cuts
        // it into windows, each honestly under the ceiling. The fold
        // twin of the visitor leg is pinned likewise in
        // BlockExtractorWindowTest.
        $this->assertCount(0, $this->extract($bigCode, ['script'], minSize: 8, maxSize: 600, windowing: false),
            'a whole-file fold may not smuggle past the maxSize cost ceiling (legacy posture)');
        $windows = $this->extract($bigCode, ['script'], minSize: 8, maxSize: 600);
        $this->assertNotEmpty($windows, 'on posture: the oversized fold survives as windows');
        foreach ($windows as $w) {
            $this->assertLessThanOrEqual(600, $w->size, 'every window respects the cost ceiling');
            $this->assertSame(BlockExtractor::WINDOW_KIND, $w->kind);
        }
        $this->assertCount(1, $this->extract($bigCode, ['script'], minSize: 8, maxSize: 8000));
    }

    public function testEmptyGroupTopLevelScopesAreDropped(): void
    {
        $groups = BlockExtractor::groupTopLevel(
            (new ParserFactory())->createForNewestSupportedVersion()
                ->parse("<?php\nnamespace Only { class Silent {} }\n") ?? [],
        );
        $this->assertSame([], $groups, 'declaration-only scope carries nothing to fold');
    }

    // ── The synthetic-wrapper round trip (probe fact pinned) ───────────

    public function testScriptBlocksRoundTripThroughNormalizerAndCaches(): void
    {
        $block = $this->scriptBlock();
        (new Normalizer('aggressive'))->normalize($block);
        $this->assertNotNull($block->canonical);
        $block->structuralHash = (new SubtreeHasher())->hash($block->canonical);
        $this->assertNotSame('', $block->structuralHash);
        $bag = (new NgramFingerprint(5))->fingerprint($block->canonical);
        $this->assertNotEmpty($bag, 'script folds fingerprint like every other kind — caches accept the wrapper');
    }

    public function testFoldAstRoundTripsThroughSerializedCaches(): void
    {
        // Fix-review M-1's persistence half: because folds are now NEVER
        // unloaded, the warm caches (IndexStore block snapshots,
        // ClusterCache member payloads) carry their synthetic AST
        // inlines. The disk caches restrict unserialize() to an explicit
        // class allow-list that already includes every PhpParser node
        // class (the docblock: "so a Block with an embedded canonical AST
        // round-trips intact") — this pin keeps that load-bearing property
        // from regressing into __PHP_Incomplete_Class → replayed folds
        // dying at the loader backstop.
        $block = $this->scriptBlock();
        $classes = \Phpdup\Persistence\SerializedClassAllowList::blockCacheClasses();

        $copy = unserialize(serialize($block), ['allowed_classes' => $classes]);
        $this->assertInstanceOf(Block::class, $copy);
        $this->assertFalse($copy->isAstUnloaded(), 'fold AST must survive the block-cache round trip');
        $this->assertInstanceOf(Stmt\Block::class, $copy->ast);

        $cluster = new Cluster('C-9', [$copy], 1.0, false);
        $clusterCopy = unserialize(serialize($cluster), ['allowed_classes' => $classes]);
        $this->assertFalse($clusterCopy->members[0]->isAstUnloaded(),
            'and through the cluster-cache payload shape too');
    }

    // ── BlockAstLoader: typed refusal, never silent mis-anchor ─────────

    public function testResolveRefusesUnloadedScriptBlock(): void
    {
        $block = $this->scriptBlock();
        $block->unloadAst();
        $this->assertTrue($block->isAstUnloaded());

        $loader = new BlockAstLoader(
            new AstCache($this->tmp . '/ast'),
            new AstParser(),
            new Config(paths: [$this->tmp], exclude: []),
        );
        try {
            $loader->resolve($block);
            $this->fail('unloaded script blocks must be refused by type');
        } catch (ScriptKindApplyRefusal $e) {
            $this->assertStringContainsString('script', $e->getMessage());
            $this->assertStringContainsString('synthetic top-level folds', $e->getMessage());
            $this->assertStringContainsString('lazy_ast=false', $e->getMessage());
        }
    }

    public function testResolveServesLoadedScriptBlockNormally(): void
    {
        // lazy_ast=false world: the fold keeps its AST; resolve must NOT
        // refuse what it can simply serve.
        $block = $this->scriptBlock();
        $loader = new BlockAstLoader(new AstCache($this->tmp . '/ast'), new AstParser(), null);
        $this->assertSame($block->ast, $loader->resolve($block));
    }

    public function testRebaseClustersRefusesReplayedScriptMember(): void
    {
        $block = $this->scriptBlock();
        $block->structuralHash = 'whatever-was-cached';
        $block->unloadAst();
        $cluster = new Cluster('C-1', [$block], 1.0, false);

        $loader = new BlockAstLoader(
            new AstCache($this->tmp . '/ast'),
            new AstParser(),
            new Config(paths: [$this->tmp], exclude: []),
        );
        $this->expectException(ScriptKindApplyRefusal::class);
        $this->expectExceptionMessage('rebaseClusters');
        $loader->rebaseClusters([$cluster], []);
    }

    public function testMatchesKindNeverPositionallyMatchesScript(): void
    {
        // Belt for the fail-loud law: the P4 relocation machinery's
        // candidate classifier must not accept 'script' — a positional
        // "match" here would be exactly the silent-mis-anchor class.
        $ref = new \ReflectionMethod(BlockAstLoader::class, 'matchesKind');
        $this->assertFalse($ref->invoke(null, new Stmt\Block([]), 'script'));
        // The refusal type crosses the wire-free layers (plain message
        // string — Socketpair-Law-neutral, it never rides a worker stream).
        $this->assertTrue(class_exists(ScriptKindApplyRefusal::class));
    }

    // ── Apply surfaces: refuse before touching disk ────────────────────

    public function testApplyExtractorRefusesScriptCluster(): void
    {
        $cluster = new Cluster('C-2', [$this->scriptBlock(), $this->scriptBlock()], 1.0, false);
        try {
            (new ApplyExtractor())->apply($cluster, $this->tmp . '/out');
            $this->fail('script clusters must be refused, not silently skipped via the generalizedAst guard');
        } catch (ScriptKindApplyRefusal $e) {
            $this->assertStringContainsString('ApplyExtractor', $e->getMessage());
            $this->assertStringContainsString('script', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->tmp . '/out', 'refusal happens before any write');
    }

    public function testPatchReporterRefusesEverySurface(): void
    {
        $cluster = new Cluster('C-3', [$this->scriptBlock(), $this->scriptBlock()], 1.0, false);
        $report = new Report(files: 2, blocks: 2, parseErrors: 0, clusters: [$cluster], config: new Config(paths: [__DIR__], exclude: []));
        $reporter = new RefactorPatchReporter();

        foreach ([
            'plain .patch'   => [$report, $this->tmp . '/plain', false, true],
            'F1a apply.diff' => [$report, $this->tmp . '/dryrun', true, true],
            'F1b real apply' => [$report, $this->tmp . '/real', true, false],
        ] as $label => $args) {
            try {
                $reporter->writeTo(...$args);
                $this->fail("script clusters must be refused on the {$label} surface");
            } catch (ScriptKindApplyRefusal $e) {
                $this->assertStringContainsString('RefactorPatchReporter', $e->getMessage(), $label);
            }
        }
        $this->assertDirectoryDoesNotExist($this->tmp . '/plain');
        $this->assertDirectoryDoesNotExist($this->tmp . '/dryrun');
        $this->assertDirectoryDoesNotExist($this->tmp . '/real');
    }

    public function testRefusalMessageNamesTheLimitationNotJustTheKind(): void
    {
        // The docs-truth exit contract renders this message on stderr via
        // the dispatch catch — it must teach the operator WHY and WHAT
        // next, not merely fail.
        $cluster = new Cluster('C-4', [$this->scriptBlock()], 1.0, false);
        try {
            (new ApplyExtractor())->apply($cluster, $this->tmp . '/out2');
            $this->fail();
        } catch (ScriptKindApplyRefusal $e) {
            $msg = $e->getMessage();
            foreach (['call sites', 'relocation', 'refused in v1'] as $fragment) {
                $this->assertStringContainsString($fragment, $msg, "message must name: {$fragment}");
            }
        }
    }
}
