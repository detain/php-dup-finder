<?php
declare(strict_types=1);

namespace App\Modules\Products;

final class ProductDto
{
    public function __construct(public int $id, public string $sku, public string $title) {}
}

final class ProductRepository
{
    public function __construct(private \PDO $pdo) {}

    public function find(int $id): ?ProductDto
    {
        $stmt = $this->pdo->prepare('SELECT id, sku, title FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new ProductDto((int) $row['id'], $row['sku'], $row['title']) : null;
    }

    public function save(ProductDto $dto): ProductDto
    {
        $stmt = $this->pdo->prepare('INSERT INTO products (sku, title) VALUES (?, ?)');
        $stmt->execute([$dto->sku, $dto->title]);
        return new ProductDto((int) $this->pdo->lastInsertId(), $dto->sku, $dto->title);
    }
}

final class ProductService
{
    public function __construct(private ProductRepository $repo) {}

    public function get(int $id): ProductDto
    {
        $dto = $this->repo->find($id);
        if ($dto === null) {
            throw new \RuntimeException('Product not found');
        }
        return $dto;
    }

    public function create(array $payload): ProductDto
    {
        return $this->repo->save(new ProductDto(0, $payload['sku'], $payload['title']));
    }
}

final class ProductController
{
    public function __construct(private ProductService $service) {}

    public function show(int $id): array
    {
        $dto = $this->service->get($id);
        return ['id' => $dto->id, 'sku' => $dto->sku, 'title' => $dto->title];
    }

    public function store(array $body): array
    {
        $dto = $this->service->create($body);
        return ['id' => $dto->id, 'sku' => $dto->sku, 'title' => $dto->title];
    }
}
