<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class AbstractRepositoryTest extends TestCase
{
    protected PDO $pdo;

    abstract protected function tableName(): string;
    abstract protected function createTableSql(): string;
    /** @return list<list<mixed>> */
    abstract protected function seedRows(): array;
    /** @return list<string> */
    abstract protected function seedColumns(): array;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec($this->createTableSql());

        $cols    = implode(', ', $this->seedColumns());
        $marks   = implode(', ', array_fill(0, count($this->seedColumns()), '?'));
        $stmt    = $this->pdo->prepare("INSERT INTO {$this->tableName()} ({$cols}) VALUES ({$marks})");

        foreach ($this->seedRows() as $row) {
            $stmt->execute($row);
        }
    }

    protected function assertFindReturns(array $found, int $expectedCount, int $firstId): void
    {
        $this->assertCount($expectedCount, $found);
        $this->assertSame($firstId, $found[0]['id']);
    }
}
