<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PHPUnit\Framework\TestCase;
use Phpdup\Normalization\SqlTableExtractor;

final class SqlTableExtractorTest extends TestCase
{
    /**
     * @dataProvider verbAndTableCases
     */
    public function testExtractsVerbAndTable(string $sql, string $expectedVerb, ?string $expectedTable): void
    {
        $result = SqlTableExtractor::extract($sql);
        $this->assertNotNull($result, "expected verb extraction to succeed for: $sql");
        $this->assertSame($expectedVerb, $result[0]);
        $this->assertSame($expectedTable, $result[1]);
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function verbAndTableCases(): iterable
    {
        yield 'simple SELECT'        => ['SELECT * FROM users WHERE id = 1', 'SELECT', 'users'];
        yield 'SELECT lowercase'     => ['select id, name from users where id = 1', 'SELECT', 'users'];
        yield 'SELECT count'         => ['select count(*) from users where active = 1', 'SELECT', 'users'];
        yield 'SELECT with backticks' => ['SELECT `id` FROM `users` WHERE `id` = ?', 'SELECT', 'users'];
        yield 'SELECT with double quotes' => ['SELECT "id" FROM "users"', 'SELECT', 'users'];
        yield 'SELECT with brackets' => ['SELECT [id] FROM [users]', 'SELECT', 'users'];
        yield 'SELECT schema-qualified' => ['SELECT * FROM public.users WHERE id = 1', 'SELECT', 'users'];

        yield 'INSERT INTO'          => ['INSERT INTO users (name) VALUES (?)', 'INSERT', 'users'];
        yield 'insert lowercase'     => ['insert into users (name) values (?)', 'INSERT', 'users'];
        yield 'INSERT IGNORE'        => ['INSERT INTO users (name) VALUES (?)', 'INSERT', 'users'];

        yield 'REPLACE INTO'         => ['REPLACE INTO users (id, name) VALUES (1, "x")', 'REPLACE', 'users'];
        yield 'REPLACE no INTO'      => ['REPLACE users (id, name) VALUES (1, "x")', 'REPLACE', 'users'];

        yield 'UPDATE'               => ['UPDATE users SET name = ? WHERE id = 1', 'UPDATE', 'users'];
        yield 'update lowercase'     => ['update users set name = ? where id = 1', 'UPDATE', 'users'];

        yield 'DELETE FROM'          => ['DELETE FROM users WHERE id = 1', 'DELETE', 'users'];
        yield 'delete lowercase'     => ['delete from users where id = 1', 'DELETE', 'users'];

        yield 'TRUNCATE TABLE'       => ['TRUNCATE TABLE users', 'DELETE', 'users'];
        yield 'TRUNCATE bare'        => ['TRUNCATE users', 'DELETE', 'users'];

        yield 'pg-style numbered placeholders' => [
            'SELECT * FROM users WHERE id = $1 AND tenant_id = $2',
            'SELECT', 'users',
        ];
    }

    public function testHandlesLeadingWhitespaceAndComments(): void
    {
        $sql = "  /* leading comment */\n  -- inline\n  SELECT * FROM accounts WHERE id = 1";
        $result = SqlTableExtractor::extract($sql);
        $this->assertNotNull($result);
        $this->assertSame('SELECT', $result[0]);
        $this->assertSame('accounts', $result[1]);
    }

    public function testStripsLeadingCte(): void
    {
        $sql = 'WITH active AS (SELECT id FROM users WHERE active = 1) SELECT * FROM active JOIN orders ON orders.user_id = active.id';
        $result = SqlTableExtractor::extract($sql);
        $this->assertNotNull($result);
        $this->assertSame('SELECT', $result[0]);
        // After stripping the CTE, the inner SELECT's FROM is `active`.
        $this->assertSame('active', $result[1]);
    }

    public function testReturnsNullForUnknownVerb(): void
    {
        $this->assertNull(SqlTableExtractor::extract('SHOW TABLES'));
        $this->assertNull(SqlTableExtractor::extract(''));
        $this->assertNull(SqlTableExtractor::extract('   '));
    }

    public function testInterpolatedPlaceholderDoesNotPolluteTable(): void
    {
        // Simulates what the canonicalizer feeds in for
        // "SELECT * FROM users WHERE id = {$id}" — interpolation
        // points become ` ? ` placeholders in the joined string.
        $sql = 'SELECT * FROM users WHERE id =  ? ';
        $result = SqlTableExtractor::extract($sql);
        $this->assertNotNull($result);
        $this->assertSame('users', $result[1]);
    }

    public function testReturnsNullWhenTableIsAPlaceholder(): void
    {
        // `SELECT * FROM ` ?  ` ` — no static table identifier we
        // can fold to, so we skip rewriting (caller falls back).
        $sql = 'SELECT * FROM  ? ';
        $result = SqlTableExtractor::extract($sql);
        $this->assertNotNull($result);
        $this->assertSame('SELECT', $result[0]);
        $this->assertNull($result[1]);
    }

    public function testIsCaseInsensitive(): void
    {
        $r1 = SqlTableExtractor::extract('SELECT * FROM Users');
        $r2 = SqlTableExtractor::extract('select * from USERS');
        $this->assertSame($r1, $r2);
    }

    /**
     * engine-048 (a): the regex capture may carry trailing punctuation, so a
     * bracket-quoted table arrives as `[users],`. The clean pass must strip
     * punctuation BEFORE bracket detection and never leak bracket residue.
     */
    public function testBracketedTableWithTrailingCommaIsCleaned(): void
    {
        $cases = [
            'SELECT * FROM [users], WHERE id = 1' => 'users',
            'SELECT * FROM [users],' => 'users',
            'SELECT * FROM [users], [orders]' => 'users', // first-table heuristic, cleaned
        ];
        foreach ($cases as $sql => $expected) {
            $result = SqlTableExtractor::extract($sql);
            $this->assertNotNull($result, "extraction should succeed for: $sql");
            $this->assertSame($expected, $result[1], "bracket residue leaked for: $sql");
        }
    }

    /** engine-048 (a): unbalanced bracket/quote residue degrades to null, never leaks. */
    public function testUnbalancedQuoteResidueDegradesToNull(): void
    {
        $sqls = [
            'SELECT * FROM [users, WHERE id = 1',     // open bracket never closes
            'SELECT * FROM users] WHERE id = 1',      // stray close bracket
            "SELECT * FROM `users], WHERE 1",         // backtick + dangling bracket
            'SELECT * FROM "users], x',               // dangling double-quote
        ];
        foreach ($sqls as $sql) {
            $result = SqlTableExtractor::extract($sql);
            $this->assertNull(
                $result === null ? null : $result[1],
                "unresolvable identifier should degrade to null for: $sql",
            );
        }
    }

    /** Balanced quoting on schema-qualified names still cleans. */
    public function testQualifiedQuotedTablesStillClean(): void
    {
        $cases = [
            'SELECT * FROM db.[users]' => 'users',
            'SELECT * FROM [db].[users]' => 'users',
            'SELECT * FROM `db`.`users`' => 'users',
            'SELECT * FROM "public"."users"' => 'users',
        ];
        foreach ($cases as $sql => $expected) {
            $result = SqlTableExtractor::extract($sql);
            $this->assertNotNull($result, "extraction should succeed for: $sql");
            $this->assertSame($expected, $result[1], "cleaning failed for: $sql");
        }
    }

    /**
     * engine-048 (b): the CTE paren walker must skip string-literal spans —
     * an unbalanced `(` inside `'a(b'` must not desync the depth count and
     * land the prefix strip mid-query.
     */
    public function testCteStripSurvivesParensInsideStringLiterals(): void
    {
        $sql = "WITH x AS (SELECT 'a(b' FROM t) SELECT * FROM final_tbl";
        $result = SqlTableExtractor::extract($sql);
        $this->assertNotNull($result);
        $this->assertSame('SELECT', $result[0]);
        $this->assertSame('final_tbl', $result[1]);

        // Same guard for double-quoted literals and backtick identifiers,
        // and for a multi-CTE list whose later body carries the trap.
        $sql2 = "WITH x AS (SELECT 1), y AS (SELECT \"d)\" FROM t) SELECT * FROM z";
        $r2 = SqlTableExtractor::extract($sql2);
        $this->assertNotNull($r2);
        $this->assertSame('z', $r2[1]);

        // Doubled single-quotes are an escaped quote, not a span end.
        $sql3 = "WITH x AS (SELECT 'O''Brien (x' FROM t) SELECT * FROM q";
        $r3 = SqlTableExtractor::extract($sql3);
        $this->assertNotNull($r3);
        $this->assertSame('q', $r3[1]);
    }

    /** Null-ish degrade stands: genuinely unbalanced statements are abandoned. */
    public function testUnbalancedCteDegradesToNull(): void
    {
        // Paren opens and never closes (the string-skip keeps it honest).
        $this->assertNull(SqlTableExtractor::extract("WITH x AS (SELECT 'a(b' FROM t SELECT * FROM y"));
        // Unterminated string literal swallows the rest of the statement.
        $this->assertNull(SqlTableExtractor::extract("WITH x AS (SELECT 'oops FROM t) SELECT * FROM y"));
    }
}
