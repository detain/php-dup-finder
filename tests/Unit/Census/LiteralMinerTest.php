<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Census;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Phpdup\Census\LiteralMiner;

/**
 * Item 3.3 substrate (MINE-E): the whole-file literal census walk. The
 * miner reads the RAW parsed tree (normalization canonicalizes literals
 * away — the census must see the values), one stack-disciplined walk per
 * file, pre-aggregating rows [kind, hash, sample, role, count, lines]
 * keyed kind\0hash\0role (key-ascending, cap MAX_ROWS_PER_FILE).
 */
final class LiteralMinerTest extends TestCase
{
    /** @return list<array{0:string,1:string,2:string,3:string,4:int,5:list<int>}> */
    private static function rowsOf(string $php): array
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast    = $parser->parse($php);
        self::assertIsArray($ast);
        return (new LiteralMiner())->mineFile(array_values($ast));
    }

    /** @return array{0:string,1:string,2:string,3:string,4:int,5:list<int>}|null */
    private static function find(array $rows, string $kind, string $sampleContains): ?array
    {
        foreach ($rows as $row) {
            if ($row[0] === $kind && str_contains($row[2], $sampleContains)) {
                return $row;
            }
        }
        return null;
    }

    public function testStringBucketLengthThreshold(): void
    {
        $rows = self::rowsOf(<<<'PHP'
            <?php
            $a = 'short one';
            $b = 'exactly_12chr!';
            $c = <<<'TXT'
            nowdoc_12chars!
            TXT;
            PHP);
        self::assertNull(self::find($rows, 'string', 'short one'), 'below the 12-byte door: not census material');
        $hit = self::find($rows, 'string', 'exactly_12chr!');
        self::assertNotNull($hit, 'the threshold is inclusive');
        self::assertSame(sha1('exactly_12chr!'), $hit[1]);
        self::assertSame('', $hit[3], 'plain strings carry no role');
        self::assertNotNull(self::find($rows, 'string', 'nowdoc_12chars!'), 'nowdoc rides the same bucket');
    }

    public function testStringRowsAggregateCountAndLineCap(): void
    {
        $php  = "<?php\n";
        $copy = "\$v = 'https://cdn.example.com/x.js';\n";
        $rows = self::rowsOf($php . str_repeat($copy, 5));
        $hit  = self::find($rows, 'string', 'cdn.example.com');
        self::assertNotNull($hit);
        self::assertSame(5, $hit[4], 'identical copies pre-aggregate per file');
        self::assertSame([2, 3, 4, 5], $hit[5], 'sample lines cap at ROW_CAP, first-seen order');
    }

    public function testNumberBucketPositionalGrouping(): void
    {
        $rows = self::rowsOf(<<<'PHP'
            <?php
            sleep(86400);
            sleep(86400);
            $t = 86400;
            $i = 0;
            $j = 1;
            $k = -1;
            $arr = [86400, 86400, 86400];
            PHP);
        $call = self::find($rows, 'number', '86400 @Arg');
        self::assertNotNull($call);
        self::assertSame(2, $call[4], 'both direct call args group into one row');
        self::assertSame([2, 3], $call[5]);
        $assign = self::find($rows, 'number', '86400 @Assign');
        self::assertNotNull($assign, 'the same value in another position-kind is another row');
        self::assertSame(1, $assign[4]);
        foreach (['0', '1', '-1'] as $trivial) {
            foreach ($rows as $r) {
                if ($r[0] === 'number') {
                    self::assertMatchesRegularExpression(
                        '/^(?!' . preg_quote($trivial, '/') . '(@|$))/',
                        $r[2],
                        "trivial counter $trivial must never become a row",
                    );
                }
            }
        }
        self::assertNull(self::find($rows, 'number', '86400 @ArrayItem'),
            'array-item numbers belong to the array signature, not the number bucket');
    }

    public function testArrayBucketIsOrderInsensitive(): void
    {
        $rows = self::rowsOf(<<<'PHP'
            <?php
            $a = ['pending' => 0, 'paid' => 1, 'refunded' => 2];
            $b = ['refunded' => 2, 'pending' => 0, 'paid' => 1];
            PHP);
        $hits = array_values(array_filter($rows, static fn(array $r): bool => $r[0] === 'array'));
        self::assertCount(1, $hits, 'the two spellings share one order-insensitive key');
        [$kind, $hash, $sample, $role, $count, $lines] = $hits[0];
        self::assertSame(2, $count);
        self::assertSame([2, 3], $lines);
        self::assertSame('"paid"=>1, "pending"=>0, "refunded"=>2', $sample,
            'entry signatures sort before hashing — sample shows the canonical order');
    }

    public function testArrayBelowDoorAndSpreadHoles(): void
    {
        $rows = self::rowsOf(<<<'PHP'
            <?php
            $two = ['a' => 1, 'b' => 2];
            $withSpread = ['a' => 1, 'b' => 2, 'c' => 3, ...$rest];
            PHP);
        $hits = array_values(array_filter($rows, static fn(array $r): bool => $r[0] === 'array'));
        self::assertCount(1, $hits, 'two entries are below the door');
        self::assertStringContainsString('=>?', $hits[0][2],
            'the spread becomes a hole sig: contents not comparable, shape still censusable');
    }

    public function testNestedArrayBelongsToOuterSignature(): void
    {
        $rows = self::rowsOf(<<<'PHP'
            <?php
            $outer = ['a' => 1, 'b' => ['deep' => 'x', 'nest' => 'y'], 'c' => 3];
            PHP);
        $hits = array_values(array_filter($rows, static fn(array $r): bool => $r[0] === 'array'));
        self::assertCount(1, $hits, 'the inner literal folds into the outer signature — one row');
    }

    public function testSkeletonBucketGroupsSqlByShapeAndPlainBySkeleton(): void
    {
        $rows = self::rowsOf(<<<'PHP'
            <?php
            $q1 = "select id from invoices where due < '$cutoff'";
            $q2 = "select sum(total) from invoices where overdue = $flag";
            $n1 = "user $name logged in at $hour today";
            $n2 = "user $sister logged in at $minute today";
            $n3 = "user $name logged out at $hour today";
            PHP);
        $sql = self::find($rows, 'skeleton', 'SELECT invoices');
        self::assertNotNull($sql);
        self::assertSame(2, $sql[4],
            'recognized SQL groups by (verb, table): the two statements touching invoices are one shape');
        $plain = self::find($rows, 'skeleton', 'user ? logged in at ? today');
        self::assertNotNull($plain, 'non-SQL interpolations group by literal skeleton');
        self::assertSame(2, $plain[4]);
        $different = self::find($rows, 'skeleton', 'logged out');
        self::assertNotNull($different, 'a different skeleton is a different row');
        self::assertSame(1, $different[4]);
    }

    public function testConstBucketDeclsAndUsesGroupByNameAcrossClasses(): void
    {
        $rows = self::rowsOf(<<<'PHP'
            <?php
            class A { const STATUS_MAP = ['x' => 1]; public function m(): int { return self::STATUS_MAP[0]; } }
            class B { const STATUS_MAP = ['x' => 1]; public function m(): int { return B::STATUS_MAP[0]; } }
            PHP);
        $hash  = sha1("NAME\1STATUS_MAP");
        $byRole = [];
        foreach ($rows as [$k, $h, $s, $r, $c, $l]) {
            if ($k === 'const' && $h === $hash) {
                $byRole[$r] = [$s, $c];
            }
        }
        self::assertSame(['STATUS_MAP', 2], $byRole['decl'] ?? null, 'two declarations, one NAME row, sample is the bare name');
        self::assertSame(['STATUS_MAP', 2], $byRole['use'] ?? null, 'self:: and B:: fetches share the NAME key (cross-class copies are the story)');
    }

    public function testEnumCasesRideTheConstBucket(): void
    {
        $rows  = self::rowsOf(<<<'PHP'
            <?php
            enum Status: string { case PENDING = 'p'; case PAID = 'q'; }
            $s = Status::PENDING;
            PHP);
        $hash  = sha1("NAME\1PENDING");
        $found = [];
        foreach ($rows as [$k, $h, $s, $r, $c, $l]) {
            if ($k === 'const' && $h === $hash) {
                $found[$r] = $c;
            }
        }
        self::assertSame(['enum_case' => 1, 'use' => 1], $found,
            'the declaration is enum_case, the fetch is use — same NAME key');
    }

    public function testRowsAreSortedAndDeterministic(): void
    {
        $php  = "<?php\n\$a = ['k1' => 1, 'k2' => 2, 'k3' => 3];\nsleep(86400);\n\$s = 'a_string_of_16_chars';\n";
        $rows = self::rowsOf($php);
        $keys = array_map(static fn(array $r): string => $r[0] . "\0" . $r[1] . "\0" . $r[3], $rows);
        $sorted = $keys;
        sort($sorted);
        self::assertSame($sorted, $keys, 'the wire is key-ascending');
        self::assertSame(json_encode(self::rowsOf($php)), json_encode($rows), 'run-to-run byte identity');
    }

    public function testPerFileRowCap(): void
    {
        $php = "<?php\n";
        for ($i = 0; $i < LiteralMiner::MAX_ROWS_PER_FILE + 5; $i++) {
            $php .= "\$v$i = 'unique_string_value_number_$i';\n";
        }
        self::assertCount(LiteralMiner::MAX_ROWS_PER_FILE, self::rowsOf($php),
            'per-file cap, head kept by key order (disclosed)');
    }
}
