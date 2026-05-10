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
}
