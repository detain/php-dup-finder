<?php

declare(strict_types=1);

namespace Tests\Integration\Migrations;

use App\Database\Migrations\Version20260201_CreateInvoices;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class CreateInvoicesMigrationTest extends TestCase
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
        $migration = new Version20260201_CreateInvoices();

        $migration->up($this->conn);

        $schema = $this->conn->createSchemaManager();
        $this->assertTrue($schema->tablesExist(['invoices']));

        $this->conn->insert('invoices', [
            'id'           => 10,
            'account_id'   => 500,
            'amount_cents' => 25000,
            'status'       => 'open',
        ]);

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM invoices');
        $this->assertSame(1, $count);

        $migration->down($this->conn);

        $this->assertFalse($schema->tablesExist(['invoices']));
    }

    public function testColumnsAreIndexed(): void
    {
        $migration = new Version20260201_CreateInvoices();
        $migration->up($this->conn);

        $indexes = $this->conn->createSchemaManager()->listTableIndexes('invoices');
        $names   = array_keys($indexes);
        $this->assertContains('idx_invoices_account', $names);
    }
}
