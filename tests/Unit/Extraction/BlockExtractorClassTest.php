<?php

declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\ApplyExtractor;
use Phpdup\Refactor\ClassKindApplyRefusal;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;

/**
 * Item 2.3 — the 'class' kind: never-implicit mint law (the one place
 * the roster CANNOT leak declarations into a default run), species
 * coverage, the enclosing-class scope convention, the declaration-shape
 * minSize exemption, the deliberate unwindowability, loader relocation
 * (a class IS re-anchorable — the opposite posture of the synthetic
 * species), and the typed apply/patch refusal.
 */
final class BlockExtractorClassTest extends TestCase
{
    /**
     * @param list<string> $allowedKinds
     * @return list<Block>
     */
    private function extract(string $code, array $allowedKinds, int $minSize = 1, int $maxSize = 100000): array
    {
        $stmts = (new AstParser())->parseCode($code);
        self::assertIsArray($stmts);
        return (new BlockExtractor($minSize, $maxSize, $allowedKinds))->extract('t.php', $stmts);
    }

    /**
     * @param list<Block> $blocks
     * @return list<Block>
     */
    private static function classes(array $blocks): array
    {
        return array_values(array_filter($blocks, static fn (Block $b): bool => $b->kind === BlockExtractor::CLASS_KIND));
    }

    public function testImplicitRosterNeverMintsClassBlocks(): void
    {
        // THE never-implicit law: empty roster = accept-all must NOT
        // start minting class blocks (default runs keep their historical
        // species set — the golden floors depend on this exact arm).
        $code = "<?php class Page { public function go(): void { \$x = 1; } }\n";
        $blocks = $this->extract($code, []);
        $this->assertSame([], $this->classes($blocks));
        $this->assertSame(['method'], array_values(array_map(static fn (Block $b): string => $b->kind, $blocks)));
    }

    public function testRosterWithoutClassStillNeverMintsIt(): void
    {
        $code = "<?php class Page { public function go(): void { \$x = 1; } }\n";
        $this->assertSame([], $this->classes($this->extract($code, ['method'])));
    }

    public function testExplicitRosterMintsEveryNamedSpecies(): void
    {
        $code = "<?php\n"
            . "namespace App;\n"
            . "class Page implements Stoppable {}\n"
            . "interface I { }\n"
            . "trait T { }\n"
            . "enum Suit { case Hearts; }\n"
            . "\$w = new class { public function f(): void {} };\n";
        $classes = $this->classes($this->extract($code, [BlockExtractor::CLASS_KIND]));

        // Anonymous classes are NOT declarations (no name to twin) —
        // never minted even under the explicit roster.
        $this->assertCount(4, $classes);
        $this->assertSame(['Page', 'I', 'T', 'Suit'], array_map(static fn (Block $b): string => (string) $b->name, $classes));
        $this->assertSame(['class', 'interface', 'trait', 'enum'], array_map(
            static fn (Block $b): string => (string) $b->ast->getType(),
            $classes,
        ));
        foreach ($classes as $block) {
            $this->assertSame('App', $block->namespace);
            $this->assertNull($block->class, 'top-level declarations have no enclosing class');
        }
    }

    public function testNestedClassesCarryTheEnclosingClassConvention(): void
    {
        $code = "<?php class Outer { class Inner { } } class Flat { }\n";
        $classes = $this->classes($this->extract($code, ['class']));
        $byName = [];
        foreach ($classes as $block) {
            $byName[(string) $block->name] = $block;
        }
        $this->assertSame(['Outer', 'Inner', 'Flat'], array_map(static fn (Block $b): string => (string) $b->name, $classes));
        $this->assertNull($byName['Outer']->class);
        $this->assertSame('Outer', $byName['Inner']->class, 'the class field is the ENCLOSING class, never self');
        $this->assertNull($byName['Flat']->class);
        // Member methods still resolve their scope to their own class.
        $methods = array_values(array_filter(
            $this->extract("<?php class Outer { public function f(): void {} }\n", ['class', 'method']),
            static fn (Block $b): bool => $b->kind === 'method',
        ));
        $this->assertSame('Outer', $methods[0]->class);
    }

    public function testTinyDeclarationSurvivesTheMinSizeFloor(): void
    {
        // Declaration shapes are the doc's target; their body mass is
        // irrelevant — minSize must not kill a two-token interface.
        $code = "<?php interface I {}\n";
        $this->assertCount(1, $this->classes($this->extract($code, ['class'], minSize: 999999)));
    }

    public function testOversizedClassIsDroppedNotWindowed(): void
    {
        // Windowing a member list into contiguous cuts would shred the
        // shape semantics (and B1's law: class-like bodies are NOT
        // lists) — the drop counter fires once, zero windows emerge.
        $code = "<?php class Big { public function a(): void { \$x = 1; \$y = 2; } public function b(): void { \$z = 3; } }\n";
        $extractor = new BlockExtractor(1, 20, ['class']);
        $blocks = $extractor->extract('t.php', (new AstParser())->parseCode($code));
        $this->assertSame([], $this->classes($blocks));
        $this->assertSame(1, $extractor->oversizedDrops());
        $this->assertSame([], array_values(array_filter($blocks, static fn (Block $b): bool => $b->kind === BlockExtractor::WINDOW_KIND)));
    }

    public function testLoaderRelocatesUnloadedClassBlocks(): void
    {
        // A class IS a parse-tree declaration — the loader serves it
        // after the lazyAst unload (no refusal arm; that is exactly the
        // divergence from script/window/fragment).
        $code = "<?php namespace App; class Page { public function go(): void {} }\n";
        $stmts = (new AstParser())->parseCode($code);
        $block = (new BlockExtractor(1, 100000, ['class']))->extract('rel.php', $stmts)[0];
        $dir = sys_get_temp_dir() . '/b2-class-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/rel.php', $code);
        $block->file = $dir . '/rel.php';
        $block->unloadAst();
        $loader = new BlockAstLoader(new AstCache(), new AstParser());
        $node = $loader->resolve($block);
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Class_::class, $node);
        $this->assertSame('Page', $node->name->toString());
        unlink($dir . '/rel.php');
        rmdir($dir);
    }

    public function testApplyExtractorRefusesClassClustersLoudly(): void
    {
        $member = new Block('x.php', new \Phpdup\Util\LineRange(1, 2), 'class', null, null, 'A', new \PhpParser\Node\Stmt\Class_('A'));
        $cluster = new Cluster('C-cls', [$member, $member], 1.0, false);
        try {
            (new ApplyExtractor())->apply($cluster, sys_get_temp_dir() . '/b2-never');
            $this->fail('class clusters must be refused before any write');
        } catch (ClassKindApplyRefusal $e) {
            $this->assertStringContainsString('ApplyExtractor', $e->getMessage());
            $this->assertStringContainsString('class-kind member(s)', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist(sys_get_temp_dir() . '/b2-never');
    }
}
