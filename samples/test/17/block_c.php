<?php

declare(strict_types=1);

namespace Tests\Shared\Database;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;
use Mockery;
use Mockery\MockInterface;

abstract class SeederTestCase extends TestCase
{
    protected Connection $connection;
    protected MockInterface $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Mockery::mock(Connection::class);
        $this->cacheService = Mockery::mock(\App\Services\CacheService::class);

        $this->setupTransactionMocks();
    }

    protected function setupTransactionMocks(): void
    {
        $this->connection->shouldReceive('beginTransaction')->once()->andReturnNull();
        $this->connection->shouldReceive('commit')->once()->andReturnNull();
        $this->connection->shouldReceive('rollback')->zeroOrMoreTimes()->andReturnNull();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function assertInsertStatement(string $table, ?string $columnValue = null): void
    {
        $this->connection->shouldReceive('executeStatement')
            ->with(
                Mockery::on(function ($sql) use ($table, $columnValue) {
                    $hasTable = strpos($sql, "INSERT INTO {$table}") !== false;
                    if ($columnValue !== null) {
                        return $hasTable && strpos($sql, $columnValue) !== false;
                    }
                    return $hasTable;
                }),
                Mockery::type('array')
            )
            ->once()
            ->andReturn(1);
    }

    protected function assertCacheClearance(array $tags): void
    {
        $this->cacheService->shouldReceive('tags')
            ->with($tags)
            ->andReturnSelf();

        $this->cacheService->shouldReceive('flush')
            ->once()
            ->andReturn(true);
    }

    protected function mockInsertFailure(string $message = 'Database error'): void
    {
        $this->connection->shouldReceive('executeStatement')
            ->once()
            ->andThrow(new \Exception($message));

        $this->connection->shouldReceive('rollback')->once();
    }
}

class UserSeederTest extends SeederTestCase
{
    public function testSeedsDefaultRoles(): void
    {
        $this->assertInsertStatement('roles', 'admin');
        $this->assertInsertStatement('roles', 'member');
        $this->assertCacheClearance(['roles']);

        // Test execution...
    }
}
