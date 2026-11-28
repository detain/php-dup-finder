<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Census;

use PHPUnit\Framework\TestCase;
use Phpdup\Census\HelperAdoption;
use Phpdup\Extraction\Block;
use Phpdup\Index\SequenceIndex;
use Phpdup\Util\LineRange;

/**
 * Item 3.2 (MINE-D-2) — the adoption analyzer over a synthetic armed
 * substrate: census groups re-queried per helper body, wildcard (abstract
 * key) grouping, statement-cap and owner-kind gates, same-file copies,
 * twin semantics and ingest-order determinism. Wire payloads follow the
 * SubtreeMiner contract exactly ([exact, abstract, nodes, rootType,
 * count, lines<=4]).
 */
final class HelperAdoptionTest extends TestCase
{
    private static function block(string $file, int $start, int $end, string $kind, ?string $class, ?string $name): Block
    {
        return new Block($file, new LineRange($start, $end), $kind, null, $class, $name, new \PhpParser\Node\Stmt\Nop());
    }

    /** @param list<array{0:string,1:string,2:int,3:string,4:int,5:list<int>}> $sites */
    private static function payload(array $sites): array
    {
        return ['sites' => $sites, 'edges' => []];
    }

    /**
     * Arm an index and feed it {file => payload}; returns a second-pass
     * nothing — purely for the recordList calls the caller adds.
     *
     * @param array<string,array{sites:list<array<int|string>>}> $perFile
     */
    private static function armedIndex(array $perFile): SequenceIndex
    {
        $index = new SequenceIndex();
        $index->armIdioms();
        $index->armHelpers();
        $census = $index->idiomCensus();
        self::assertNotNull($census);
        foreach ($perFile as $file => $payload) {
            $census->recordFile($file, $payload);
        }
        return $index;
    }

    public function testFindsHelperPatternWithCrossFileCopies(): void
    {
        // helper method body at a.php 10-12 carries K once (:11);
        // K is re-implemented twice in b.php and once in c.php.
        $index = self::armedIndex([
            'a.php' => self::payload([['K', 'A', 8, 'Expr_Cond', 1, [11]]]),
            'b.php' => self::payload([['K', 'A', 8, 'Expr_Cond', 2, [7, 9]]]),
            'c.php' => self::payload([['K', 'A', 8, 'Expr_Cond', 1, [4]]]),
        ]);
        $index->recordList('a.php', ['h1'], 11, 11, 'method');
        $index->recordList('b.php', ['h2', 'h3'], 6, 12, 'method');
        $index->recordList('c.php', ['h4'], 4, 4, 'function');

        $adoption = HelperAdoption::analyze($index, [
            self::block('a.php', 10, 12, 'method', 'Helper', 'flag'),
        ]);

        $rows = $adoption->rows();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('Helper::flag', $row->helperLabel);
        self::assertSame('method', $row->helperKind);
        self::assertSame(1, $row->defStatements);
        self::assertSame(4, $row->sitesTotal);
        self::assertSame(1, $row->defSites);
        self::assertSame(3, $row->inlineSites);
        self::assertSame(['b.php' => 2, 'c.php' => 1], $row->inlineFiles);
        self::assertSame(2, $row->inlineFileCount());
        self::assertSame(['b.php:7', 'b.php:9', 'c.php:4'], $row->copyLocations, 'def-file in-span sample excluded, copies canonical');
        self::assertSame('K', $row->patternExact);
        self::assertSame('A', $row->patternAbstract);
        self::assertSame(8, $row->nodes);
        self::assertSame('Expr_Cond', $row->rootType);
        self::assertSame(1, $adoption->helperCount());
        self::assertSame(1, $adoption->patternsFound());
        self::assertSame(3, $adoption->inlineSitesTotal());
    }

    /*
     | Caller leg (MINE-E, item 3.2 follow-on riding 3.5): callers is
     | null while call_sequence is unarmed, an honest 0 when armed with
     | no hits, and receiver-blind counted when the census carries the
     | helper's name being called — outside the helper's own span.
     */

    public function testCallersNullWhenCallCensusUnarmed(): void
    {
        $index = self::armedIndex([
            'a.php' => self::payload([['K', 'A', 8, 'Expr_Cond', 1, [11]]]),
            'b.php' => self::payload([['K', 'A', 8, 'Expr_Cond', 1, [7]]]),
        ]);
        $index->recordList('a.php', ['h1'], 11, 11, 'method');

        $rows = HelperAdoption::analyze($index, [
            self::block('a.php', 10, 12, 'method', 'Helper', 'flag'),
        ])->rows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]->callers, 'absence is honest-null, never a 0-lie');
        self::assertSame([], $rows[0]->callerLocations);
    }

    public function testCallersCountedReceiverBlindOutsideOwnSpan(): void
    {
        $index = self::armedIndex([
            'a.php' => self::payload([['K', 'A', 8, 'Expr_Cond', 1, [11]]]),
            'b.php' => self::payload([['K', 'A', 8, 'Expr_Cond', 1, [7]]]),
        ]);
        $index->recordList('a.php', ['h1'], 11, 11, 'method');

        // Call witnesses: 'Helper::flag' (static form), 'flag' (method/
        // function form), 'Logger::flag' (receiver-blind over-count, the
        // disclosed v1 posture), 'flagged'/'otherFlag' must never match;
        // a.php:11 sits INSIDE the helper span (excluded), a.php:20 outside.
        $index->armCalls();
        $index->recordCallFile('b.php', json_encode([[
            'names' => ['Helper::flag', 'log'], 'lines' => [5, 6], 'args' => [[], []],
            'firstLine' => 5, 'lastLine' => 6,
        ]]));
        $index->recordCallFile('c.php', json_encode([[
            'names' => ['flag'], 'lines' => [4], 'args' => [[]],
            'firstLine' => 4, 'lastLine' => 4,
        ]]));
        $index->recordCallFile('d.php', json_encode([[
            'names' => ['Logger::flag', 'flagged', 'otherFlag'], 'lines' => [9, 9, 9],
            'args' => [[], [], []], 'firstLine' => 9, 'lastLine' => 9,
        ]]));
        $index->recordCallFile('a.php', json_encode([[
            'names' => ['Helper::flag', 'flag'], 'lines' => [11, 20], 'args' => [[], []],
            'firstLine' => 11, 'lastLine' => 20,
        ]]));

        $rows = HelperAdoption::analyze($index, [
            self::block('a.php', 10, 12, 'method', 'Helper', 'flag'),
        ])->rows();
        self::assertCount(1, $rows);
        self::assertSame(4, $rows[0]->callers, 'b:5 + c:4 + d:9 + a:20; in-span a:11 excluded');
        self::assertSame(['a.php:20', 'b.php:5', 'c.php:4', 'd.php:9'], $rows[0]->callerLocations);
    }

    public function testCallersZeroWhenArmedButUnseen(): void
    {
        $index = self::armedIndex([
            'a.php' => self::payload([['K', 'A', 8, 'Expr_Cond', 1, [11]]]),
            'b.php' => self::payload([['K', 'A', 8, 'Expr_Cond', 1, [7]]]),
        ]);
        $index->recordList('a.php', ['h1'], 11, 11, 'method');
        $index->armCalls();
        $index->recordCallFile('b.php', json_encode([[
            'names' => ['log'], 'lines' => [3], 'args' => [[]],
            'firstLine' => 3, 'lastLine' => 3,
        ]]));

        $rows = HelperAdoption::analyze($index, [
            self::block('a.php', 10, 12, 'method', 'Helper', 'flag'),
        ])->rows();
        self::assertCount(1, $rows);
        self::assertSame(0, $rows[0]->callers, 'armed census with no witness is an honest 0');
        self::assertSame([], $rows[0]->callerLocations);
    }

    public function testLiteralDriftGroupsUnderTheWildcardKey(): void
    {
        // Two exact spellings share abstract A: K1 (helper's own line +
        // b.php), K2 (twice in c.php). One abstract-group row must
        // account ALL of it: def=1, inline=3, copies b:1 c:2.
        $index = self::armedIndex([
            'a.php' => self::payload([['K1', 'A', 6, 'Expr_BinaryOp_Identical', 1, [12]]]),
            'b.php' => self::payload([['K1', 'A', 6, 'Expr_BinaryOp_Identical', 1, [5]]]),
            'c.php' => self::payload([['K2', 'A', 6, 'Expr_BinaryOp_Identical', 2, [7, 9]]]),
        ]);
        $index->recordList('a.php', ['h1'], 12, 12, 'method');

        $adoption = HelperAdoption::analyze($index, [
            self::block('a.php', 10, 14, 'method', null, 'dev'),
        ]);

        $rows = $adoption->rows();
        self::assertCount(1, $rows, 'one row per (helper, abstract group), not per exact');
        self::assertSame('K1', $rows[0]->patternExact, 'representative is the lexicographically smallest exact');
        self::assertSame(4, $rows[0]->sitesTotal);
        self::assertSame(1, $rows[0]->defSites);
        self::assertSame(3, $rows[0]->inlineSites);
        self::assertSame(['b.php' => 1, 'c.php' => 2], $rows[0]->inlineFiles);
        self::assertSame(1, $adoption->patternsFound());
    }

    public function testBodyOverTheStatementCapIsNotAHelper(): void
    {
        $index = self::armedIndex([
            'a.php' => self::payload([['K', 'A', 6, 'Expr_x', 1, [12]]]),
            'b.php' => self::payload([['K', 'A', 6, 'Expr_x', 1, [3]]]),
        ]);
        $index->recordList('a.php', ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 11, 20, 'method');

        $adoption = HelperAdoption::analyze($index, [
            self::block('a.php', 10, 21, 'method', 'Big', 'run'),
        ]);
        self::assertSame([], $adoption->rows(), 'the doc letter: helpers are small bodies (<= ' . HelperAdoption::HELPER_MAX_STATEMENTS . ' statements)');
    }

    public function testOwnerKindOrRangeMismatchYieldsNoBody(): void
    {
        $index = self::armedIndex([
            'a.php' => self::payload([['K', 'A', 6, 'Expr_x', 1, [12]]]),
            'b.php' => self::payload([['K', 'A', 6, 'Expr_x', 1, [3]]]),
        ]);
        // Lists exist but neither matches (kind, span-in-block):
        $index->recordList('a.php', ['h1'], 12, 12, 'closure');      // wrong owner kind
        $index->recordList('a.php', ['h2'], 12, 12, 'if-body');       // wrong owner kind
        $index->recordList('a.php', ['h3'], 30, 30, 'method');        // outside the block range

        $adoption = HelperAdoption::analyze($index, [
            self::block('a.php', 10, 14, 'method', 'M', 'm'),
        ]);
        self::assertSame([], $adoption->rows(), 'a method body the walker never listed cannot claim helper statements');
    }

    public function testPatternExclusiveToHelperBodyIsNotReimplemented(): void
    {
        $index = self::armedIndex([
            'a.php' => self::payload([['K', 'A', 6, 'Expr_x', 2, [11, 13]]]),
        ]);
        $index->recordList('a.php', ['h1'], 11, 13, 'method');

        $adoption = HelperAdoption::analyze($index, [
            self::block('a.php', 10, 14, 'method', 'M', 'm'),
        ]);
        self::assertSame([], $adoption->rows(), 'sites == def means nobody re-implemented it');
    }

    public function testSameFileCopiesOutsideTheRangeAreCounted(): void
    {
        $index = self::armedIndex([
            'a.php' => self::payload([['K', 'A', 6, 'Expr_x', 2, [11, 40]]]),
            'b.php' => self::payload([['K', 'A', 6, 'Expr_x', 1, [4]]]),
        ]);
        $index->recordList('a.php', ['h1'], 11, 11, 'method');

        $adoption = HelperAdoption::analyze($index, [
            self::block('a.php', 10, 12, 'method', 'M', 'm'),
        ]);

        $rows = $adoption->rows();
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]->defSites);
        self::assertSame(['a.php' => 1, 'b.php' => 1], $rows[0]->inlineFiles, 'a second copy in the same file outside the helper counts');
        self::assertContains('a.php:40', $rows[0]->copyLocations, 'out-of-span same-file sample survives the def filter');
        self::assertNotContains('a.php:11', $rows[0]->copyLocations);
    }

    public function testTwinHelpersEachAccountTheGroup(): void
    {
        // Both small helpers contain K; from either seat the other body
        // is a copy (disclosed v1 twin posture; patternsFound stays 1).
        $index = self::armedIndex([
            'a.php' => self::payload([['K', 'A', 6, 'Expr_x', 1, [11]]]),
            'b.php' => self::payload([['K', 'A', 6, 'Expr_x', 1, [5]]]),
        ]);
        $index->recordList('a.php', ['h1'], 11, 11, 'method');
        $index->recordList('b.php', ['h2'], 5, 5, 'function');

        $adoption = HelperAdoption::analyze($index, [
            self::block('a.php', 10, 12, 'method', 'M', 'm'),
            self::block('b.php', 3, 9, 'function', null, 'f'),
        ]);

        $rows = $adoption->rows();
        self::assertCount(2, $rows);
        self::assertSame(['a.php', 'b.php'], array_map(static fn($r) => $r->defFile, $rows), 'sorted by defFile');
        self::assertSame(1, $rows[0]->inlineSites);
        self::assertSame(1, $rows[1]->inlineSites);
        self::assertSame(1, $adoption->patternsFound(), 'one idiom, two def seats');
        self::assertSame(2, $adoption->inlineSitesTotal(), 'twin double-count is the disclosed posture');
    }

    public function testNonHelperKindsAndAnonymousNamesIgnored(): void
    {
        $index = self::armedIndex([
            'a.php' => self::payload([['K', 'A', 6, 'Expr_x', 1, [11]]]),
            'b.php' => self::payload([['K', 'A', 6, 'Expr_x', 1, [5]]]),
        ]);
        $index->recordList('a.php', ['h1'], 11, 11, 'method');

        $adoption = HelperAdoption::analyze($index, [
            self::block('a.php', 9, 20, 'class', 'M', null),
            self::block('a.php', 10, 12, 'closure', null, null),
        ]);
        self::assertSame([], $adoption->rows(), 'classes and closures are not helper definitions');
    }

    public function testDeterminismUnderPermutedIngest(): void
    {
        $files = [
            'a.php' => self::payload([['K', 'A', 6, 'Expr_x', 1, [11]], ['J', 'B', 9, 'Stmt_y', 1, [12]]]),
            'b.php' => self::payload([['K', 'A', 6, 'Expr_x', 1, [5]], ['J', 'B', 9, 'Stmt_y', 1, [7]]]),
            'c.php' => self::payload([['K', 'A', 6, 'Expr_x', 1, [8]], ['J', 'B', 9, 'Stmt_y', 2, [3, 20]]]),
        ];
        $blocks = [self::block('a.php', 10, 14, 'method', 'M', 'm')];

        $forward = self::armedIndex($files);
        $reverse = self::armedIndex(array_reverse($files, true));
        $forward->recordList('a.php', ['h1', 'h2'], 11, 12, 'method');
        $reverse->recordList('a.php', ['h1', 'h2'], 11, 12, 'method');

        $render = static function (array $rows): string {
            return json_encode(array_map(static fn($r) => [
                $r->defFile, $r->defStart, $r->patternAbstract, $r->patternExact,
                $r->sitesTotal, $r->defSites, $r->inlineSites, $r->inlineFiles, $r->copyLocations,
            ], $rows));
        };
        self::assertSame(
            $render(HelperAdoption::analyze($forward, $blocks)->rows()),
            $render(HelperAdoption::analyze($reverse, $blocks)->rows()),
        );
    }

    public function testRequiresArmedCensusAndArmedHelpers(): void
    {
        $bare = new SequenceIndex();
        try {
            HelperAdoption::analyze($bare, []);
            self::fail('adoption over an unbuilt census must refuse');
        } catch (\LogicException $e) {
            self::assertStringContainsString('idiom census', $e->getMessage());
        }

        $censusOnly = new SequenceIndex();
        $censusOnly->armIdioms();
        try {
            $censusOnly->buildHelpers([]);
            self::fail('build while the adoption flavor is unarmed must refuse');
        } catch (\LogicException $e) {
            self::assertStringContainsString('armHelpers', $e->getMessage());
        }

        $censusOnly->armHelpers();
        self::assertNotNull($censusOnly->buildHelpers([]), 'a clean corpus yields an empty but present ledger');
        self::assertNotNull($censusOnly->helperAdoption());
        self::assertSame([], $censusOnly->helperAdoption()->rows());
    }
}
