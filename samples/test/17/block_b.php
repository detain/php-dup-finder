<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use App\Database\Seeders\ProductSeeder;
use App\Database\Seeders\CategorySeeder;
use App\Database\Seeders\ManufacturerSeeder;
use App\Services\CacheService;
use Doctrine\DBAL\Connection;
use Mockery;

class ProductSeederTest extends TestCase
{
    private Connection $connection;
    private CacheService $cacheService;
    private ProductSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Mockery::mock(Connection::class);
        $this->cacheService = Mockery::mock(CacheService::class);

        $this->seeder = new ProductSeeder($this->connection, $this->cacheService);

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

    public function testSeedsCategories(): void
    {
        $categories = [
            ['name' => 'Electronics', 'slug' => 'electronics', 'parent_id' => null],
            ['name' => 'Computers', 'slug' => 'computers', 'parent_id' => 1],
            ['name' => 'Phones', 'slug' => 'phones', 'parent_id' => 1],
            ['name' => 'Accessories', 'slug' => 'accessories', 'parent_id' => null],
        ];

        $this->connection->shouldReceive('executeStatement')
            ->with(
                Mockery::on(function ($sql) {
                    return strpos($sql, 'INSERT INTO categories') !== false;
                }),
                Mockery::type('array')
            )
            ->times(4)
            ->andReturn(1);

        $this->connection->shouldReceive('lastInsertId')->andReturn(1, 2, 3, 4);

        $this->cacheService->shouldReceive('tags')->with(['categories'])->andReturnSelf();
        $this->cacheService->shouldReceive('flush')->once()->andReturn(true);

        $this->seeder->seedCategories($categories);
    }

    public function testSeedsManufacturers(): void
    {
        $manufacturers = [
            ['name' => 'TechCorp', 'slug' => 'techcorp', 'country' => 'US'],
            ['name' => 'GlobalDevices', 'slug' => 'globaldevices', 'country' => 'DE'],
            ['name' => 'AsiaElectronics', 'slug' => 'asiaelectronics', 'country' => 'JP'],
        ];

        $this->connection->shouldReceive('executeStatement')
            ->with(
                Mockery::on(function ($sql) {
                    return strpos($sql, 'INSERT INTO manufacturers') !== false;
                }),
                Mockery::type('array')
            )
            ->times(3)
            ->andReturn(1);

        $this->connection->shouldReceive('lastInsertId')->andReturn(1, 2, 3);

        $this->cacheService->shouldReceive('tags')->with(['manufacturers'])->andReturnSelf();
        $this->cacheService->shouldReceive('flush')->once()->andReturn(true);

        $this->seeder->seedManufacturers($manufacturers);
    }

    public function testSeedsSampleProducts(): void
    {
        $products = [
            [
                'sku' => 'LAPTOP-001',
                'name' => 'Professional Laptop',
                'price' => 129999,
                'category_id' => 2,
                'manufacturer_id' => 1,
            ],
            [
                'sku' => 'PHONE-001',
                'name' => 'Smartphone Pro',
                'price' => 89999,
                'category_id' => 3,
                'manufacturer_id' => 1,
            ],
        ];

        $this->connection->shouldReceive('executeStatement')
            ->with(
                Mockery::on(function ($sql) {
                    return strpos($sql, 'INSERT INTO products') !== false;
                }),
                Mockery::type('array')
            )
            ->times(2)
            ->andReturn(1);

        $this->connection->shouldReceive('lastInsertId')->andReturn(1, 2);

        $this->cacheService->shouldReceive('tags')->with(['products'])->andReturnSelf();
        $this->cacheService->shouldReceive('flush')->once()->andReturn(true);

        $this->seeder->seedProducts($products);
    }

    public function testRollsBackOnFailure(): void
    {
        $this->connection->shouldReceive('executeStatement')
            ->once()
            ->andThrow(new \Exception('Database error'));

        $this->connection->shouldReceive('rollback')->once();

        $this->expectException(\Exception::class);
        $this->seeder->seedCategories([]);
    }

    public function testClearsCacheAfterSeeding(): void
    {
        $this->connection->shouldReceive('executeStatement')
            ->anyTimes()
            ->andReturn(1);

        $cacheCleared = false;
        $this->cacheService->shouldReceive('tags')
            ->with(['products', 'categories', 'manufacturers'])
            ->andReturnUsing(function () use (&$cacheCleared) {
                $cacheCleared = true;
                return $this->cacheService;
            });

        $this->seeder->run();

        $this->assertTrue($cacheCleared, 'Cache should be cleared after seeding');
    }
}
