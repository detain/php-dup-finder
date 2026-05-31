<?php
declare(strict_types=1);

namespace App\Modules\Orders;

final class OrderDto
{
    public function __construct(public int $id, public string $reference, public int $total) {}
}

final class OrderRepository
{
    public function __construct(private \PDO $pdo) {}

    public function find(int $id): ?OrderDto
    {
        $stmt = $this->pdo->prepare('SELECT id, reference, total FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new OrderDto((int) $row['id'], $row['reference'], (int) $row['total']) : null;
    }

    public function save(OrderDto $dto): OrderDto
    {
        $stmt = $this->pdo->prepare('INSERT INTO orders (reference, total) VALUES (?, ?)');
        $stmt->execute([$dto->reference, $dto->total]);
        return new OrderDto((int) $this->pdo->lastInsertId(), $dto->reference, $dto->total);
    }
}

final class OrderService
{
    public function __construct(private OrderRepository $repo) {}

    public function get(int $id): OrderDto
    {
        $dto = $this->repo->find($id);
        if ($dto === null) {
            throw new \RuntimeException('Order not found');
        }
        return $dto;
    }

    public function create(array $payload): OrderDto
    {
        return $this->repo->save(new OrderDto(0, $payload['reference'], (int) $payload['total']));
    }
}

final class OrderController
{
    public function __construct(private OrderService $service) {}

    public function show(int $id): array
    {
        $dto = $this->service->get($id);
        return ['id' => $dto->id, 'reference' => $dto->reference, 'total' => $dto->total];
    }

    public function store(array $body): array
    {
        $dto = $this->service->create($body);
        return ['id' => $dto->id, 'reference' => $dto->reference, 'total' => $dto->total];
    }
}
