<?php

declare(strict_types=1);

namespace Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\TestCase;

abstract class AbstractMigrationTestCase extends TestCase
{
    protected Connection $conn;

    abstract protected function migration(): AbstractMigration;
    abstract protected function tableName(): string;

    /** @return array<string, mixed> */
    abstract protected function seedRow(): array;

    /** @return list<string> */
    abstract protected function expectedIndexes(): array;

    protected function setUp(): void
    {
        $this->conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }

    public function testUpCreatesTableAndDownRemovesIt(): void
    {
        $migration = $this->migration();
        $migration->up($this->conn);

        $schema = $this->conn->createSchemaManager();
        $this->assertTrue($schema->tablesExist([$this->tableName()]));

        $this->conn->insert($this->tableName(), $this->seedRow());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ' . $this->tableName());
        $this->assertSame(1, $count);

        $migration->down($this->conn);
        $this->assertFalse($schema->tablesExist([$this->tableName()]));
    }

    public function testColumnsAreIndexed(): void
    {
        $this->migration()->up($this->conn);
        $names = array_keys($this->conn->createSchemaManager()->listTableIndexes($this->tableName()));
        foreach ($this->expectedIndexes() as $idx) {
            $this->assertContains($idx, $names);
        }
    }
}
