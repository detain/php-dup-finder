<?php

declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use PhpParser\Node;
use PhpParser\ParserFactory;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Item 2.1 — the 'fragment' miner leg of BlockExtractor: opt-in posture,
 * list selection, window shape, size floors, whole-block-equality flags,
 * and the §5c list-accessor contract. (Suppression/maximality semantics
 * live PreprocessStage-side — see PreprocessStageFragmentTest.)
 */
final class BlockExtractorFragmentTest extends TestCase
{
    /** The twelve traversable kinds (the implicit accept-all roster). */
    private const TRAVERSABLE = [
        'function', 'method', 'closure', 'arrow',
        'if', 'for', 'foreach', 'while', 'do',
        'try', 'switch', 'match',
    ];

    /**
     * @param list<string> $allowedKinds
     * @return list<Block>
     */
    private function extract(string $code, array $allowedKinds, int $minSize = 1, int $maxSize = 100000): array
    {
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        self::assertIsArray($stmts);
        return (new BlockExtractor($minSize, $maxSize, $allowedKinds))->extract('t.php', $stmts);
    }

    /**
     * @param list<Block> $blocks
     * @return list<Block>
     */
    private static function fragments(array $blocks): array
    {
        return array_values(array_filter($blocks, static fn (Block $b): bool => $b->kind === BlockExtractor::FRAGMENT_KIND));
    }

    public function testImplicitRosterNeverMintsFragments(): void
    {
        $code = "<?php\nfunction go(\$id) {\n  \$a = one(\$id);\n  \$b = two(\$a);\n  \$c = three(\$b);\n  \$d = four(\$c);\n  return \$d;\n}\n";
        foreach ([[], self::TRAVERSABLE] as $roster) {
            self::assertSame([], self::fragments($this->extract($code, $roster)), 'fragment must never be implicit');
        }
    }

    public function testNamedSelectionMintsBodyRunsAcrossStatementUnits(): void
    {
        $code = "<?php\nfunction go(\$id) {\n  \$a = one(\$id);\n  \$b = two(\$a);\n  \$c = three(\$b);\n  \$d = four(\$c);\n  return \$d;\n}\n";
        $blocks = $this->extract($code, self::TRAVERSABLE);
        $withFragment = $this->extract($code, [...self::TRAVERSABLE, BlockExtractor::FRAGMENT_KIND]);

        // bodies: 5 statements at lines 3..7 -> sizes 3 (x3), 4 (x2), 5 (x1)
        $frags = self::fragments($withFragment);
        self::assertCount(6, $frags);
        $shapes = [];
        foreach ($frags as $f) {
            $shapes[] = $f->range->start . '..' . $f->range->end . '#' . $f->fragmentPos . '+' . $f->fragmentSize;
        }
        self::assertSame([
            '3..5#0+3', '4..6#1+3', '5..7#2+3',
            '3..6#0+4', '4..7#1+4',
            '3..7#0+5',
        ], $shapes);

        // Non-fragment blocks are the same species and count as before.
        $base = array_values(array_filter($blocks, static fn (Block $b): bool => $b->kind !== BlockExtractor::FRAGMENT_KIND));
        $added = array_values(array_filter($withFragment, static fn (Block $b): bool => $b->kind !== BlockExtractor::FRAGMENT_KIND));
        self::assertSameSize($base, $added);

        // whole-list run equals the declaration body -> whole flag (the
        // function block was minted by this very roster).
        $whole = array_values(array_filter($frags, static fn (Block $b): bool => $b->fragmentWhole));
        self::assertCount(1, $whole);
        self::assertSame('3..7', $whole[0]->range->start . '..' . $whole[0]->range->end);
    }

    public function testClassMemberListsAreNotStatementLists(): void
    {
        $code = "<?php\nclass C {\n  public const A = 1;\n  public \$p = 2;\n  public function m1() { go(); }\n  public function m2() { stop(); }\n  public function m3() { wait(); }\n  public function m4() { done(); }\n}\n";
        $frags = self::fragments($this->extract($code, [...self::TRAVERSABLE, BlockExtractor::FRAGMENT_KIND]));
        // method BODIES are one statement each (< k); the class body is
        // NOT a list (members are declarations, already units).
        self::assertSame([], $frags);
    }

    public function testControlBodiesAreListsAndWholeBranchRunsSurvive(): void
    {
        $code = "<?php\nfunction outer(\$x) {\n  if (\$x) {\n    \$a = one();\n    \$b = two();\n    \$c = three();\n  }\n}\n";
        $frags = self::fragments($this->extract($code, [...self::TRAVERSABLE, BlockExtractor::FRAGMENT_KIND]));
        // lists: the if BODY (3 stmts, lines 4-6) and the function body
        // (1 stmt, the if@3-7 -> no run). Whole-list rule applies to
        // declarations only, so the body run is NOT flagged whole — the
        // branch body-as-fragment is exactly the admin-guard finding.
        self::assertCount(1, $frags);
        self::assertSame('4..6', $frags[0]->range->start . '..' . $frags[0]->range->end);
        self::assertFalse($frags[0]->fragmentWhole);
        self::assertSame(3, $frags[0]->fragmentSize);
    }

    public function testTopLevelWholeRunFlagDependsOnScriptFoldBeingMinted(): void
    {
        $code = "<?php\n\$rows = [];\n\$db = get_db();\n\$res = \$db->query('x');\n";
        $onlyFragment = self::fragments($this->extract($code, [BlockExtractor::FRAGMENT_KIND]));
        self::assertCount(1, $onlyFragment, 'the whole-bucket run is a legitimate fragment when no fold exists');
        self::assertFalse($onlyFragment[0]->fragmentWhole);

        $both = self::fragments($this->extract($code, ['script', BlockExtractor::FRAGMENT_KIND]));
        self::assertCount(1, $both);
        self::assertTrue($both[0]->fragmentWhole, 'the script fold already minted this exact range');

        $traversablePlus = self::fragments($this->extract($code, [...self::TRAVERSABLE, BlockExtractor::FRAGMENT_KIND]));
        self::assertCount(1, $traversablePlus);
        self::assertFalse($traversablePlus[0]->fragmentWhole, 'script is never implicit — same law');
    }

    public function testRunCapIsDocConstant(): void
    {
        $body = '';
        for ($i = 0; $i < 45; $i++) {
            $body .= "  \$s$i = step$i();\n";
        }
        $code = "<?php\nfunction big(\$id) {\n{$body}}\n";
        $frags = self::fragments($this->extract($code, [...self::TRAVERSABLE, BlockExtractor::FRAGMENT_KIND]));
        self::assertNotSame([], $frags);
        $sizes = array_unique(array_map(static fn (Block $f): int => $f->fragmentSize, $frags));
        sort($sizes);
        self::assertSame(range(BlockExtractor::FRAGMENT_MIN_STATEMENTS, BlockExtractor::FRAGMENT_MAX_STATEMENTS), $sizes);
    }

    public function testSizeFloorsAndCeilingsGateRuns(): void
    {
        $code = "<?php\nfunction go(\$id) {\n  \$a = one(\$id);\n  \$b = two(\$a);\n  \$c = three(\$b);\n  return \$c;\n}\n";
        // floor: every 3-run is tiny; a minSize above them kills all
        // windows, while the declaration itself still mints (the
        // top-level-abstraction exemption is visitor-side, unaffected).
        $floored = $this->extract($code, [...self::TRAVERSABLE, BlockExtractor::FRAGMENT_KIND], 5000, 100000);
        self::assertSame([], self::fragments($floored));
        self::assertNotSame([], array_values(array_filter($floored, static fn (Block $b): bool => $b->kind === 'function')));
        // ceiling: a maxSize below a 3-run's node count likewise produces
        // no fragments — a run above the cost ceiling dies, it is NOT
        // re-windowed (these ARE the cuts).
        self::assertSame([], self::fragments($this->extract($code, [...self::TRAVERSABLE, BlockExtractor::FRAGMENT_KIND], 1, 10)));
    }

    public function testDeterministicOrderAndIdentityAcrossRuns(): void
    {
        $code = "<?php\n\$x = one();\nclass C {\n  public function m() {\n    \$a = one();\n    \$b = two();\n    \$c = three();\n    \$d = four();\n  }\n}\n\$y = two();\n\$z = three();\n\$w = four();\n";
        $first = $this->extract($code, [...self::TRAVERSABLE, BlockExtractor::FRAGMENT_KIND]);
        $second = $this->extract($code, [...self::TRAVERSABLE, BlockExtractor::FRAGMENT_KIND]);
        $sig = static fn (array $bs): array => array_map(static fn (Block $b): string => $b->kind . '@' . $b->range->start, $bs);
        self::assertSame($sig($first), $sig($second));
    }

    public function testStatementListsAccessorContract(): void
    {
        $code = "<?php\n\$u = one();\n\$v = two();\n\$w = three();\nfunction go() {\n  \$a = one();\n  \$b = two();\n  \$c = three();\n}\n";
        $extractor = new BlockExtractor(1, 100000, [...self::TRAVERSABLE, BlockExtractor::FRAGMENT_KIND]);
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        self::assertIsArray($stmts);

        $idle = new BlockExtractor(1, 100000, self::TRAVERSABLE);
        $idle->extract('t.php', $stmts);
        self::assertSame([], $idle->lastStatementLists(), 'demand-gated: no kind, no lists');

        $blocks = $extractor->extract('t.php', $stmts);
        $lists = $extractor->lastStatementLists();
        self::assertCount(2, $lists, 'function body + top-level bucket');
        self::assertSame('function', $lists[0]['ownerKind']);
        self::assertSame('', $lists[1]['ownerKind']);
        foreach ($lists as $list) {
            self::assertArrayHasKey('stmts', $list);
            self::assertIsString($list['ownerKind']);
            foreach ($list['fragments'] as $f) {
                self::assertContains($f, $blocks, 'minted fragments are the SAME objects the caller received');
            }
        }
    }

    public function testNamespaceContextTravelsToFragmentBlocks(): void
    {
        $code = "<?php\nnamespace App\\Kid {\n  function go() {\n    \$a = one();\n    \$b = two();\n    \$c = three();\n  }\n}\n";
        $frags = self::fragments($this->extract($code, [...self::TRAVERSABLE, BlockExtractor::FRAGMENT_KIND]));
        self::assertNotSame([], $frags);
        self::assertSame('App\\Kid', $frags[0]->namespace);
    }
}
