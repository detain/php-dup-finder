<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use App\Database\Seeders\UserSeeder;
use App\Database\Seeders\RoleSeeder;
use App\Database\Seeders\PermissionSeeder;
use App\Services\CacheService;
use Doctrine\DBAL\Connection;
use Mockery;

class UserSeederTest extends TestCase
{
    private Connection $connection;
    private CacheService $cacheService;
    private UserSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Mockery::mock(Connection::class);
        $this->cacheService = Mockery::mock(CacheService::class);

        $this->seeder = new UserSeeder($this->connection, $this->cacheService);

        // Mock transaction behavior
        $this->connection->shouldReceive('beginTransaction')->once()->andReturnNull();
        $this->connection->shouldReceive('commit')->once()->andReturnNull();
        $this->connection->shouldReceive('rollback')->zeroOrMoreTimes()->andReturnNull();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSeedsDefaultRoles(): void
    {
        $this->connection->shouldReceive('executeStatement')
            ->with(
                Mockery::on(function ($sql) {
                    return strpos($sql, 'INSERT INTO roles') !== false
                        && strpos($sql, 'admin') !== false
                        && strpos($sql, 'member') !== false;
                }),
                Mockery::type('array')
            )
            ->times(3)
            ->andReturn(3);

        $this->cacheService->shouldReceive('tags')->with(['roles'])->andReturnSelf();
        $this->cacheService->shouldReceive('flush')->once()->andReturn(true);

        $this->seeder->seedRoles();
    }

    public function testSeedsDefaultPermissions(): void
    {
        $permissions = [
            ['slug' => 'users.read', 'name' => 'Read Users', 'description' => 'View user records'],
            ['slug' => 'users.write', 'name' => 'Write Users', 'description' => 'Create/edit users'],
            ['slug' => 'users.delete', 'name' => 'Delete Users', 'description' => 'Remove users'],
            ['slug' => 'roles.read', 'name' => 'Read Roles', 'description' => 'View role records'],
            ['slug' => 'roles.write', 'name' => 'Write Roles', 'description' => 'Create/edit roles'],
        ];

        $this->connection->shouldReceive('executeStatement')
            ->with(
                Mockery::on(function ($sql) {
                    return strpos($sql, 'INSERT INTO permissions') !== false;
                }),
                Mockery::type('array')
            )
            ->times(5)
            ->andReturn(1);

        $this->cacheService->shouldReceive('tags')->with(['permissions'])->andReturnSelf();
        $this->cacheService->shouldReceive('flush')->once()->andReturn(true);

        $this->seeder->seedPermissions();
    }

    public function testSeedsAdminUser(): void
    {
        $this->connection->shouldReceive('executeStatement')
            ->with(
                Mockery::on(function ($sql) {
                    return strpos($sql, 'INSERT INTO users') !== false
                        && strpos($sql, 'admin@example.com') !== false;
                }),
                Mockery::type('array')
            )
            ->once()
            ->andReturn(1);

        $this->connection->shouldReceive('lastInsertId')->andReturn(1);

        $this->connection->shouldReceive('executeStatement')
            ->with(
                Mockery::on(function ($sql) {
                    return strpos($sql, 'INSERT INTO user_roles') !== false;
                }),
                Mockery::type('array')
            )
            ->once()
            ->andReturn(1);

        $this->cacheService->shouldReceive('tags')->with(['users'])->andReturnSelf();
        $this->cacheService->shouldReceive('flush')->once()->andReturn(true);

        $this->seeder->seedAdminUser([
            'email' => 'admin@example.com',
            'password' => password_hash('changeme123', PASSWORD_DEFAULT),
            'name' => 'System Administrator',
        ]);

        $this->connection->shouldReceive('lastInsertId')->andReturn(1);
    }

    public function testRollsBackOnFailure(): void
    {
        $this->connection->shouldReceive('executeStatement')
            ->once()
            ->andThrow(new \Exception('Database error'));

        $this->connection->shouldReceive('rollback')->once();

        $this->expectException(\Exception::class);
        $this->seeder->seedRoles();
    }

    public function testClearsCacheAfterSeeding(): void
    {
        $this->connection->shouldReceive('executeStatement')
            ->anyTimes()
            ->andReturn(1);

        $cacheCleared = false;
        $this->cacheService->shouldReceive('tags')
            ->with(['users', 'roles', 'permissions'])
            ->andReturnUsing(function () use (&$cacheCleared) {
                $cacheCleared = true;
                return $this->cacheService;
            });

        $this->seeder->run();

        $this->assertTrue($cacheCleared, 'Cache should be cleared after seeding');
    }
}
