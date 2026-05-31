<?php

declare(strict_types=1);

namespace Tests\Integration\Migrations;

use App\Database\Migrations\Version20260101_CreateCustomers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class CreateCustomersMigrationTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }

    public function testUpCreatesTableAndDownRemovesIt(): void
    {
        $migration = new Version20260101_CreateCustomers();

        $migration->up($this->conn);

        $schema = $this->conn->createSchemaManager();
        $this->assertTrue($schema->tablesExist(['customers']));

        $this->conn->insert('customers', [
            'id'           => 1,
            'company_name' => 'Acme',
            'email'        => 'a@acme.test',
            'region_id'    => 1,
        ]);

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM customers');
        $this->assertSame(1, $count);

        $migration->down($this->conn);

        $this->assertFalse($schema->tablesExist(['customers']));
    }

    public function testColumnsAreIndexed(): void
    {
        $migration = new Version20260101_CreateCustomers();
        $migration->up($this->conn);

        $indexes = $this->conn->createSchemaManager()->listTableIndexes('customers');
        $names   = array_keys($indexes);
        $this->assertContains('idx_customers_email', $names);
    }
}
