<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Repository\InvoiceRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class InvoiceRepositoryTest extends TestCase
{
    private PDO $pdo;
    private InvoiceRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE invoices (
            id INTEGER PRIMARY KEY,
            account_id INTEGER NOT NULL,
            amount_cents INTEGER NOT NULL,
            status TEXT NOT NULL,
            due_at TEXT NOT NULL
        )');

        $stmt = $this->pdo->prepare('INSERT INTO invoices (id, account_id, amount_cents, status, due_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([10, 500, 25000, 'open', '2026-02-15']);
        $stmt->execute([11, 500, 30000, 'paid', '2026-02-20']);
        $stmt->execute([12, 600, 18000, 'open', '2026-02-22']);

        $this->repo = new InvoiceRepository($this->pdo);
    }

    public function testFindByAccountReturnsRows(): void
    {
        $invoices = $this->repo->findByAccount(500);

        $this->assertCount(2, $invoices);
        $this->assertSame(10, $invoices[0]['id']);
        $this->assertSame('open', $invoices[0]['status']);
        $this->assertSame(25000, $invoices[0]['amount_cents']);
    }

    public function testFindByAccountReturnsEmptyArrayForUnknown(): void
    {
        $invoices = $this->repo->findByAccount(9999);
        $this->assertSame([], $invoices);
    }
}
