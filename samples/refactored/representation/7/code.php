<?php
declare(strict_types=1);

namespace App\Audit;

enum AuditSeverity: string
{
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
    case Critical = 'critical';
}

final class AuditEvent
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $actorId,
        public readonly string $actorLabel,
        public readonly string $action,
        public readonly string $targetType,
        public readonly string $targetId,
        public readonly AuditSeverity $severity,
        public readonly ?array $context,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
        if ($actorId === '' || $action === '') {
            throw new \InvalidArgumentException('Actor/action required');
        }
    }

    public static function fromArray(array $a): self
    {
        return new self(
            isset($a['id']) ? (int)$a['id'] : null,
            (string)$a['actor_id'],
            (string)($a['actor_label'] ?? ''),
            (string)$a['action'],
            (string)($a['target_type'] ?? ''),
            (string)($a['target_id'] ?? ''),
            AuditSeverity::from((string)($a['severity'] ?? 'info')),
            is_array($a['context'] ?? null) ? $a['context'] : null,
            new \DateTimeImmutable((string)($a['at'] ?? 'now')),
        );
    }

    public function toWebhookBody(): array
    {
        return [
            'event_type' => 'audit.' . $this->action,
            'actor' => ['id' => $this->actorId, 'name' => $this->actorLabel],
            'target' => ['type' => $this->targetType, 'id' => $this->targetId],
            'severity' => $this->severity->value,
            'context' => $this->context,
            'occurred_at' => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'version' => '1.0',
        ];
    }
}
