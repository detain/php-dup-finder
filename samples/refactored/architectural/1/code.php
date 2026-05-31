<?php
declare(strict_types=1);

namespace App\Crud;

/** @template T of object */
abstract class CrudRepository
{
    public function __construct(protected \PDO $pdo, protected string $table, protected array $columns) {}

    /** @return T|null */
    public function find(int $id): ?object
    {
        $cols = implode(',', array_merge(['id'], $this->columns));
        $stmt = $this->pdo->prepare("SELECT {$cols} FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** @param T $dto @return T */
    public function save(object $dto): object
    {
        $placeholders = implode(',', array_fill(0, count($this->columns), '?'));
        $cols = implode(',', $this->columns);
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} ({$cols}) VALUES ({$placeholders})");
        $stmt->execute(array_map(fn(string $c) => $dto->$c, $this->columns));
        $dto->id = (int) $this->pdo->lastInsertId();
        return $dto;
    }

    /** @return T */
    abstract protected function hydrate(array $row): object;
}

/** @template T of object */
final class CrudService
{
    /** @param CrudRepository<T> $repo */
    public function __construct(private CrudRepository $repo, private string $label) {}

    /** @return T */
    public function get(int $id): object
    {
        $dto = $this->repo->find($id);
        if ($dto === null) {
            throw new \RuntimeException("{$this->label} not found");
        }
        return $dto;
    }
}

final class CrudController
{
    public function __construct(private CrudService $service) {}

    public function show(int $id): array
    {
        return (array) $this->service->get($id);
    }
}
