<?php

declare(strict_types=1);

namespace Tests\Shared\Integration;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;
use Mockery;
use Mockery\MockInterface;

abstract class ServiceIntegrationTestCase extends TestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->createConnectionMock();
        $this->setupTransactionMocks();
    }

    protected function createConnectionMock(): Connection
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

    protected function setupTransactionMocks(): void
    {
        $this->connection->shouldReceive('beginTransaction')->andReturnNull();
        $this->connection->shouldReceive('commit')->andReturnNull();
        $this->connection->shouldReceive('rollback')->andReturnNull();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function assertStatementExecuted(string $sqlPattern): void
    {
        $this->connection->shouldReceive('executeStatement')
            ->with(
                Mockery::on(function ($sql) use ($sqlPattern) {
                    return strpos($sql, $sqlPattern) !== false;
                }),
                Mockery::type('array')
            )
            ->once();
    }
}

class BillingServiceIntegrationTest extends ServiceIntegrationTestCase
{
    private BillingService $billingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billingService = new BillingService(
            $this->connection,
            $this->paymentGateway,
            $this->invoiceService,
            $this->subscriptionService
        );
    }

    public function testProcessPaymentForInvoice(): void
    {
        $this->assertStatementExecuted('UPDATE invoices');
        $this->connection->shouldReceive('commit')->once();

        // Test execution...
    }
}
