<?php
declare(strict_types=1);

namespace App\Modules\Customers;

final class CustomerDto
{
    public function __construct(public int $id, public string $name, public string $email) {}
}

final class CustomerRepository
{
    public function __construct(private \PDO $pdo) {}

    public function find(int $id): ?CustomerDto
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new CustomerDto((int) $row['id'], $row['name'], $row['email']) : null;
    }

    public function save(CustomerDto $dto): CustomerDto
    {
        $stmt = $this->pdo->prepare('INSERT INTO customers (name, email) VALUES (?, ?)');
        $stmt->execute([$dto->name, $dto->email]);
        return new CustomerDto((int) $this->pdo->lastInsertId(), $dto->name, $dto->email);
    }
}

final class CustomerService
{
    public function __construct(private CustomerRepository $repo) {}

    public function get(int $id): CustomerDto
    {
        $dto = $this->repo->find($id);
        if ($dto === null) {
            throw new \RuntimeException('Customer not found');
        }
        return $dto;
    }

    public function create(array $payload): CustomerDto
    {
        return $this->repo->save(new CustomerDto(0, $payload['name'], $payload['email']));
    }
}

final class CustomerController
{
    public function __construct(private CustomerService $service) {}

    public function show(int $id): array
    {
        $dto = $this->service->get($id);
        return ['id' => $dto->id, 'name' => $dto->name, 'email' => $dto->email];
    }

    public function store(array $body): array
    {
        $dto = $this->service->create($body);
        return ['id' => $dto->id, 'name' => $dto->name, 'email' => $dto->email];
    }
}
