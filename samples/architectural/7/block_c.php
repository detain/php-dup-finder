<?php
declare(strict_types=1);

namespace App\Domain\Tenants;

final class TenantCreated
{
    public function __construct(public string $id, public string $slug, public \DateTimeImmutable $occurredAt) {}
}

final class Tenant
{
    /** @var list<object> */
    private array $events = [];

    private function __construct(public readonly string $id, public string $slug, public string $tier) {}

    public static function reconstitute(string $id, string $slug, string $tier): self
    {
        return new self($id, $slug, $tier);
    }

    public static function open(string $id, string $slug): self
    {
        $agg = new self($id, $slug, 'free');
        $agg->events[] = new TenantCreated($id, $slug, new \DateTimeImmutable());
        return $agg;
    }

    /** @return list<object> */
    public function pullEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }
}

final class TenantFactory
{
    public function create(string $id, string $slug): Tenant
    {
        if ($id === '' || $slug === '') {
            throw new \InvalidArgumentException('id and slug required');
        }
        return Tenant::open($id, $slug);
    }
}

final class TenantRepository
{
    public function __construct(private \PDO $pdo) {}

    public function save(Tenant $agg): void
    {
        $stmt = $this->pdo->prepare('REPLACE INTO tenants (id, slug, tier) VALUES (?, ?, ?)');
        $stmt->execute([$agg->id, $agg->slug, $agg->tier]);
    }

    public function load(string $id): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT id, slug, tier FROM tenants WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? Tenant::reconstitute($row['id'], $row['slug'], $row['tier']) : null;
    }
}
