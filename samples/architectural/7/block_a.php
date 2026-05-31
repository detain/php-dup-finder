<?php
declare(strict_types=1);

namespace App\Domain\Subscriptions;

final class SubscriptionCreated
{
    public function __construct(public string $id, public string $plan, public \DateTimeImmutable $occurredAt) {}
}

final class Subscription
{
    /** @var list<object> */
    private array $events = [];

    private function __construct(public readonly string $id, public string $plan, public string $status) {}

    public static function reconstitute(string $id, string $plan, string $status): self
    {
        return new self($id, $plan, $status);
    }

    public static function open(string $id, string $plan): self
    {
        $agg = new self($id, $plan, 'active');
        $agg->events[] = new SubscriptionCreated($id, $plan, new \DateTimeImmutable());
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

final class SubscriptionFactory
{
    public function create(string $id, string $plan): Subscription
    {
        if ($id === '' || $plan === '') {
            throw new \InvalidArgumentException('id and plan required');
        }
        return Subscription::open($id, $plan);
    }
}

final class SubscriptionRepository
{
    public function __construct(private \PDO $pdo) {}

    public function save(Subscription $agg): void
    {
        $stmt = $this->pdo->prepare('REPLACE INTO subscriptions (id, plan, status) VALUES (?, ?, ?)');
        $stmt->execute([$agg->id, $agg->plan, $agg->status]);
    }

    public function load(string $id): ?Subscription
    {
        $stmt = $this->pdo->prepare('SELECT id, plan, status FROM subscriptions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? Subscription::reconstitute($row['id'], $row['plan'], $row['status']) : null;
    }
}
