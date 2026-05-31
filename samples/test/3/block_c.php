<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Repository\ShipmentRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class ShipmentRepositoryTest extends TestCase
{
    private PDO $pdo;
    private ShipmentRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE shipments (
            id INTEGER PRIMARY KEY,
            order_id INTEGER NOT NULL,
            weight_grams INTEGER NOT NULL,
            status TEXT NOT NULL,
            shipped_at TEXT NOT NULL
        )');

        $stmt = $this->pdo->prepare('INSERT INTO shipments (id, order_id, weight_grams, status, shipped_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([100, 1, 500, 'in_transit', '2026-03-01']);
        $stmt->execute([101, 1, 800, 'delivered',  '2026-03-05']);
        $stmt->execute([102, 2, 250, 'in_transit', '2026-03-07']);

        $this->repo = new ShipmentRepository($this->pdo);
    }

    public function testFindByOrderReturnsRows(): void
    {
        $shipments = $this->repo->findByOrder(1);

        $this->assertCount(2, $shipments);
        $this->assertSame(100, $shipments[0]['id']);
        $this->assertSame('in_transit', $shipments[0]['status']);
        $this->assertSame(500, $shipments[0]['weight_grams']);
    }

    public function testFindByOrderReturnsEmptyArrayForUnknown(): void
    {
        $shipments = $this->repo->findByOrder(999);
        $this->assertSame([], $shipments);
    }
}
