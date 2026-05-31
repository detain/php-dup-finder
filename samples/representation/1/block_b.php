<?php
declare(strict_types=1);

namespace Acme\Crm\Persistence;

final class CustomerRow
{
    public int $id = 0;
    public string $first_name = '';
    public string $last_name = '';
    public string $email = '';
    public ?string $phone = null;
    public \DateTimeImmutable $created_at;
    public \DateTimeImmutable $updated_at;

    public function __construct()
    {
        $this->created_at = new \DateTimeImmutable();
        $this->updated_at = new \DateTimeImmutable();
    }

    public static function hydrate(array $row): self
    {
        if (!filter_var($row['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Corrupt row: bad email');
        }
        if (empty($row['first_name']) || empty($row['last_name'])) {
            throw new \RuntimeException('Corrupt row: missing name');
        }
        $self = new self();
        $self->id = (int)$row['id'];
        $self->first_name = trim((string)$row['first_name']);
        $self->last_name = trim((string)$row['last_name']);
        $self->email = strtolower(trim((string)$row['email']));
        $self->phone = isset($row['phone']) ? trim((string)$row['phone']) : null;
        $self->created_at = new \DateTimeImmutable((string)$row['created_at']);
        $self->updated_at = new \DateTimeImmutable((string)$row['updated_at']);
        return $self;
    }

    public function fullName(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}

final class CustomerRepository
{
    public function __construct(private \PDO $pdo) {}

    public function find(int $id): ?CustomerRow
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? CustomerRow::hydrate($row) : null;
    }
}
