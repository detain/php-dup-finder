<?php
declare(strict_types=1);

namespace App\Domain\Workspaces;

final class WorkspaceCreated
{
    public function __construct(public string $id, public string $name, public \DateTimeImmutable $occurredAt) {}
}

final class Workspace
{
    /** @var list<object> */
    private array $events = [];

    private function __construct(public readonly string $id, public string $name, public string $visibility) {}

    public static function reconstitute(string $id, string $name, string $visibility): self
    {
        return new self($id, $name, $visibility);
    }

    public static function open(string $id, string $name): self
    {
        $agg = new self($id, $name, 'private');
        $agg->events[] = new WorkspaceCreated($id, $name, new \DateTimeImmutable());
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

final class WorkspaceFactory
{
    public function create(string $id, string $name): Workspace
    {
        if ($id === '' || $name === '') {
            throw new \InvalidArgumentException('id and name required');
        }
        return Workspace::open($id, $name);
    }
}

final class WorkspaceRepository
{
    public function __construct(private \PDO $pdo) {}

    public function save(Workspace $agg): void
    {
        $stmt = $this->pdo->prepare('REPLACE INTO workspaces (id, name, visibility) VALUES (?, ?, ?)');
        $stmt->execute([$agg->id, $agg->name, $agg->visibility]);
    }

    public function load(string $id): ?Workspace
    {
        $stmt = $this->pdo->prepare('SELECT id, name, visibility FROM workspaces WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? Workspace::reconstitute($row['id'], $row['name'], $row['visibility']) : null;
    }
}
