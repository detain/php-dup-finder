<?php

declare(strict_types=1);

namespace Tests\Integration\Migrations;

use App\Database\Migrations\Version20260301_CreateAuditLog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class CreateAuditLogMigrationTest extends TestCase
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
        $migration = new Version20260301_CreateAuditLog();

        $migration->up($this->conn);

        $schema = $this->conn->createSchemaManager();
        $this->assertTrue($schema->tablesExist(['audit_log']));

        $this->conn->insert('audit_log', [
            'id'         => 1,
            'actor_id'   => 99,
            'action'     => 'login',
            'created_at' => '2026-05-01 00:00:00',
        ]);

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM audit_log');
        $this->assertSame(1, $count);

        $migration->down($this->conn);

        $this->assertFalse($schema->tablesExist(['audit_log']));
    }

    public function testColumnsAreIndexed(): void
    {
        $migration = new Version20260301_CreateAuditLog();
        $migration->up($this->conn);

        $indexes = $this->conn->createSchemaManager()->listTableIndexes('audit_log');
        $names   = array_keys($indexes);
        $this->assertContains('idx_audit_log_actor', $names);
    }
}
