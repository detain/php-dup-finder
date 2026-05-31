<?php
declare(strict_types=1);

namespace Security\Audit\Persistence;

final class AuditLogRow
{
    public int $id = 0;
    public string $actor_id = '';
    public string $actor_label = '';
    public string $action = '';
    public string $target_type = '';
    public string $target_id = '';
    public string $severity = 'info';
    public ?string $context_json = null;
    public \DateTimeImmutable $occurred_at;

    public function __construct()
    {
        $this->occurred_at = new \DateTimeImmutable();
    }

    public static function fromRecord(array $rec): self
    {
        if (empty($rec['actor_id']) || empty($rec['action'])) {
            throw new \InvalidArgumentException('Actor/action required');
        }
        if (!in_array($rec['severity'] ?? 'info', ['info', 'warn', 'error', 'critical'], true)) {
            throw new \InvalidArgumentException('Invalid severity');
        }
        $self = new self();
        $self->id = (int)($rec['id'] ?? 0);
        $self->actor_id = (string)$rec['actor_id'];
        $self->actor_label = (string)($rec['actor_label'] ?? '');
        $self->action = (string)$rec['action'];
        $self->target_type = (string)($rec['target_type'] ?? '');
        $self->target_id = (string)($rec['target_id'] ?? '');
        $self->severity = (string)($rec['severity'] ?? 'info');
        $self->context_json = isset($rec['context']) ? json_encode($rec['context'], JSON_THROW_ON_ERROR) : null;
        $self->occurred_at = isset($rec['at'])
            ? new \DateTimeImmutable((string)$rec['at'])
            : new \DateTimeImmutable();
        return $self;
    }
}

final class AuditRepository
{
    public function __construct(private \PDO $pdo) {}

    public function append(AuditLogRow $row): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO audit_log (actor_id, action, severity, occurred_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$row->actor_id, $row->action, $row->severity, $row->occurred_at->format('c')]);
        return (int)$this->pdo->lastInsertId();
    }
}
