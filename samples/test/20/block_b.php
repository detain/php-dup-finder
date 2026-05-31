<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use PHPUnit\Framework\TestCase;
use App\Services\InventoryService;
use App\Services\WarehouseService;
use App\Services\SupplierService;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\InventoryLevel;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ProductNotFoundException;
use Doctrine\DBAL\Connection;
use Mockery;

class InventoryServiceIntegrationTest extends TestCase
{
    private InventoryService $inventoryService;
    private Connection $connection;
    private WarehouseService $warehouseService;
    private SupplierService $supplierService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createConnectionMock();
        $this->warehouseService = Mockery::mock(WarehouseService::class);
        $this->supplierService = Mockery::mock(SupplierService::class);

        $this->inventoryService = new InventoryService(
            $this->connection,
            $this->warehouseService,
            $this->supplierService
        );

        $this->connection->shouldReceive('beginTransaction')->andReturnNull();
        $this->connection->shouldReceive('commit')->andReturnNull();
        $this->connection->shouldReceive('rollback')->andReturnNull();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createConnectionMock(): Connection
    {
        $mock = Mockery::mock(Connection::class);

        $mock->shouldReceive('executeQuery')
            ->andReturn(Mockery::mock(\Doctrine\DBAL\Result::class));

        $mock->shouldReceive('executeStatement')
            ->andReturn(1);

        $mock->shouldReceive('createQueryBuilder')
            ->andReturn(Mockery::mock(\Doctrine\DBAL\Query\QueryBuilder::class));

        return $mock;
    }

    private function createTestProduct(array $overrides = []): Product
    {
        $defaults = [
            'id' => 1,
            'sku' => 'PROD-001',
            'name' => 'Test Product',
            'inventory_count' => 100,
            'low_stock_threshold' => 10,
            'reorder_point' => 20,
            'reorder_quantity' => 50,
            'status' => 'active',
        ];

        return new Product(array_merge($defaults, $overrides));
    }

    private function createTestWarehouse(array $overrides = []): Warehouse
    {
        $defaults = [
            'id' => 1,
            'code' => 'WH-MAIN',
            'name' => 'Main Warehouse',
            'location' => 'New York, NY',
            'capacity' => 10000,
            'current_utilization' => 5000,
        ];

        return new Warehouse(array_merge($defaults, $overrides));
    }

    private function createInventoryLevel(array $overrides = []): InventoryLevel
    {
        $defaults = [
            'id' => 1,
            'product_id' => 1,
            'warehouse_id' => 1,
            'quantity' => 50,
            'reserved_quantity' => 5,
            'available_quantity' => 45,
        ];

        return new InventoryLevel(array_merge($defaults, $overrides));
    }

    public function testDecrementsInventoryOnOrder(): void
    {
        $product = $this->createTestProduct(['inventory_count' => 100]);
        $warehouse = $this->createTestWarehouse();

        $this->warehouseService
            ->shouldReceive('findPrimaryForProduct')
            ->with(1)
            ->andReturn($warehouse);

        $this->connection
            ->shouldReceive('executeStatement')
            ->with(
                Mockery::on(function ($sql) {
                    return strpos($sql, 'UPDATE inventory_levels') !== false
                        && strpos($sql, 'quantity = quantity - 10') !== false;
                }),
                Mockery::type('array')
            )
            ->once();

        $this->connection
            ->shouldReceive('commit')
            ->once();

        $result = $this->inventoryService->decrement(1, 10, 'ORD-001');

        $this->assertTrue($result);
    }

    public function testThrowsExceptionForInsufficientStock(): void
    {
        $product = $this->createTestProduct(['inventory_count' => 5]);
        $warehouse = $this->createTestWarehouse();

        $this->warehouseService
            ->shouldReceive('findPrimaryForProduct')
            ->with(1)
            ->andReturn($warehouse);

        $this->expectException(InsufficientStockException::class);
        $this->inventoryService->decrement(1, 10, 'ORD-001');
    }

    public function testReordersInventoryWhenBelowReorderPoint(): void
    {
        $product = $this->createTestProduct([
            'inventory_count' => 15,
            'reorder_point' => 20,
            'reorder_quantity' => 50,
        ]);

        $warehouse = $this->createTestWarehouse();
        $inventoryLevel = $this->createInventoryLevel(['quantity' => 15]);

        $this->warehouseService
            ->shouldReceive('findPrimaryForProduct')
            ->with(1)
            ->andReturn($warehouse);

        $this->supplierService
            ->shouldReceive('createReorder')
            ->with(1, 50, 'WH-MAIN')
            ->once();

        $this->connection
            ->shouldReceive('commit')
            ->once();

        $result = $this->inventoryService->checkAndReorder(1);

        $this->assertTrue($result);
    }

    public function testReservesInventoryForOrder(): void
    {
        $product = $this->createTestProduct(['inventory_count' => 100]);
        $warehouse = $this->createTestWarehouse();
        $inventoryLevel = $this->createInventoryLevel(['quantity' => 100]);

        $this->warehouseService
            ->shouldReceive('findPrimaryForProduct')
            ->with(1)
            ->andReturn($warehouse);

        $this->connection
            ->shouldReceive('executeStatement')
            ->with(
                Mockery::on(function ($sql) {
                    return strpos($sql, 'UPDATE inventory_levels') !== false
                        && strpos($sql, 'reserved_quantity = reserved_quantity + 10') !== false;
                }),
                Mockery::type('array')
            )
            ->once();

        $this->connection
            ->shouldReceive('commit')
            ->once();

        $result = $this->inventoryService->reserve(1, 10, 'ORD-001');

        $this->assertTrue($result);
    }

    public function testRestoresInventoryOnOrderCancellation(): void
    {
        $product = $this->createTestProduct(['inventory_count' => 90]);
        $warehouse = $this->createTestWarehouse();

        $this->warehouseService
            ->shouldReceive('findPrimaryForProduct')
            ->with(1)
            ->andReturn($warehouse);

        $this->connection
            ->shouldReceive('executeStatement')
            ->with(
                Mockery::on(function ($sql) {
                    return strpos($sql, 'UPDATE inventory_levels') !== false
                        && strpos($sql, 'quantity = quantity + 10') !== false;
                }),
                Mockery::type('array')
            )
            ->once();

        $this->connection
            ->shouldReceive('commit')
            ->once();

        $result = $this->inventoryService->restore(1, 10, 'ORD-001');

        $this->assertTrue($result);
    }
}
