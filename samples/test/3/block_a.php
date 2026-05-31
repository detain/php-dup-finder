<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Repository\OrderRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class OrderRepositoryTest extends TestCase
{
    private PDO $pdo;
    private OrderRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE orders (
            id INTEGER PRIMARY KEY,
            customer_id INTEGER NOT NULL,
            total_cents INTEGER NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');

        $stmt = $this->pdo->prepare('INSERT INTO orders (id, customer_id, total_cents, status, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([1, 100, 1999, 'paid', '2026-01-01']);
        $stmt->execute([2, 100, 4999, 'pending', '2026-01-02']);
        $stmt->execute([3, 200, 999,  'paid', '2026-01-03']);

        $this->repo = new OrderRepository($this->pdo);
    }

    public function testFindByCustomerReturnsRows(): void
    {
        $orders = $this->repo->findByCustomer(100);

        $this->assertCount(2, $orders);
        $this->assertSame(1, $orders[0]['id']);
        $this->assertSame('paid', $orders[0]['status']);
        $this->assertSame(1999, $orders[0]['total_cents']);
    }

    public function testFindByCustomerReturnsEmptyArrayForUnknown(): void
    {
        $orders = $this->repo->findByCustomer(999);
        $this->assertSame([], $orders);
    }
}
