<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PHPUnit\Framework\TestCase;
use Phpdup\Normalization\DbOpRegistry;

final class DbOpRegistryTest extends TestCase
{
    public function testEloquentReadVerbs(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_READ, $r->lookupMethod('find'));
        $this->assertSame(DbOpRegistry::OP_READ, $r->lookupMethod('findOrFail'));
        $this->assertSame(DbOpRegistry::OP_READ, $r->lookupMethod('first'));
        $this->assertSame(DbOpRegistry::OP_READ, $r->lookupMethod('all'));
        $this->assertSame(DbOpRegistry::OP_READ, $r->lookupMethod('get'));
    }

    public function testDoctrineWriteVerbs(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('persist'));
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('flush'));
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('save'));
    }

    public function testDeletes(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_DELETE, $r->lookupMethod('delete'));
        $this->assertSame(DbOpRegistry::OP_DELETE, $r->lookupMethod('destroy'));
        $this->assertSame(DbOpRegistry::OP_DELETE, $r->lookupMethod('remove'));
    }

    public function testGenericQueryAndExecute(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupMethod('query'));
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $r->lookupMethod('execute'));
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $r->lookupMethod('prepare'));
    }

    public function testMysqliFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('mysqli_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('mysqli_fetch_assoc'));
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $r->lookupFunction('mysqli_stmt_execute'));
    }

    public function testPostgresFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('pg_query'));
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('pg_query_params'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('pg_fetch_assoc'));
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupFunction('pg_insert'));
        $this->assertSame(DbOpRegistry::OP_DELETE, $r->lookupFunction('pg_delete'));
    }

    public function testCaseInsensitiveLookup(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame($r->lookupMethod('find'),  $r->lookupMethod('FIND'));
        $this->assertSame($r->lookupMethod('save'),  $r->lookupMethod('Save'));
        $this->assertSame($r->lookupFunction('pg_query'), $r->lookupFunction('PG_QUERY'));
    }

    public function testReturnsNullForUnknownNames(): void
    {
        $r = DbOpRegistry::default();
        $this->assertNull($r->lookupMethod('frobnicate'));
        $this->assertNull($r->lookupFunction('strlen'));
    }

    public function testCustomMethodOverridesStock(): void
    {
        $r = new DbOpRegistry(customMethodOps: ['find' => DbOpRegistry::OP_WRITE]);
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('find'));
    }

    public function testCustomFunctionExtendsStock(): void
    {
        $r = new DbOpRegistry(customFunctionOps: ['my_db_query' => DbOpRegistry::OP_QUERY]);
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('my_db_query'));
        // Stock entries still resolve.
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('mysqli_query'));
    }

    public function testBuilderIntermediatesAreNotMapped(): void
    {
        // Verify that intermediate query-builder calls (which return
        // a Builder, not a result) are intentionally NOT in the
        // registry — the *terminal* call carries the canonical op.
        $r = DbOpRegistry::default();
        $this->assertNull($r->lookupMethod('where'));
        $this->assertNull($r->lookupMethod('whereIn'));
        $this->assertNull($r->lookupMethod('orderBy'));
        $this->assertNull($r->lookupMethod('limit'));
        $this->assertNull($r->lookupMethod('with'));
        $this->assertNull($r->lookupMethod('join'));
    }

    public function testAsyncTransactionMethods(): void
    {
        $r = DbOpRegistry::default();
        // Transaction methods
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('beginTransaction'));
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('commit'));
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('rollback'));
    }

    public function testAsyncAuxiliaryMethods(): void
    {
        $r = DbOpRegistry::default();
        // Async auxiliary methods
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupMethod('affectedRows'));
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('insertId'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupMethod('count'));
    }

    public function testAsyncAmphpMySqlFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('amysql_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('amysql_fetch_assoc'));
    }

    public function testAsyncReactMySqlFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('react_mysql_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('react_mysql_fetch_assoc'));
    }

    public function testAsyncSwooleCoroutineMySqlFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('swoole_mysql_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('swoole_mysql_fetch_assoc'));
    }

    public function testAsyncWorkermanMySqlFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('workerman_mysql_query'));
    }

    public function testAsyncAmphpPostgresFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('apg_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('apg_fetch_assoc'));
    }

    public function testAsyncReactPostgresFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('react_pg_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('react_pg_fetch_assoc'));
    }

    public function testAsyncSwooleCoroutinePostgresFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('swoole_postgres_query'));
    }

    public function testOracleOci8Functions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $r->lookupFunction('oci_parse'));
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $r->lookupFunction('oci_execute'));
        $this->assertSame(DbOpRegistry::OP_READ,    $r->lookupFunction('oci_fetch'));
        $this->assertSame(DbOpRegistry::OP_WRITE,   $r->lookupFunction('oci_commit'));
        $this->assertSame(DbOpRegistry::OP_WRITE,   $r->lookupFunction('oci_rollback'));
    }

    public function testCassandraPhpcassaFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('phpcassa_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('phpcassa_fetch'));
    }

    public function testLevelDbFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_READ,    $r->lookupFunction('leveldb_get'));
        $this->assertSame(DbOpRegistry::OP_WRITE,   $r->lookupFunction('leveldb_put'));
        $this->assertSame(DbOpRegistry::OP_DELETE,  $r->lookupFunction('leveldb_delete'));
    }

    public function testMemcachedFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_READ,    $r->lookupFunction('memcached_get'));
        $this->assertSame(DbOpRegistry::OP_WRITE,   $r->lookupFunction('memcached_set'));
        $this->assertSame(DbOpRegistry::OP_DELETE,  $r->lookupFunction('memcached_delete'));
    }
}
