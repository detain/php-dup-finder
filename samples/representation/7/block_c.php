<?php
declare(strict_types=1);

namespace Admin\Audit\Ui;

final class AuditDisplayItem
{
    public string $eventId;
    public string $whoLabel;
    public string $whatHuman;
    public string $targetHuman;
    public string $severityBadge;
    public string $whenRelative;
    public string $whenAbsolute;
    public ?array $contextPreview;

    public function fromEvent(array $event): void
    {
        if (empty($event['actor_id']) || empty($event['action'])) {
            throw new \InvalidArgumentException('Actor/action required for display');
        }
        if (!in_array($event['severity'] ?? 'info', ['info', 'warn', 'error', 'critical'], true)) {
            throw new \InvalidArgumentException('Invalid severity for display');
        }
        $this->eventId = (string)($event['id'] ?? '');
        $this->whoLabel = (string)($event['actor_label'] ?: $event['actor_id']);
        $verbs = ['create' => 'created', 'update' => 'updated', 'delete' => 'deleted', 'login' => 'signed in'];
        $action = (string)$event['action'];
        $this->whatHuman = $verbs[$action] ?? $action;
        $this->targetHuman = trim(((string)($event['target_type'] ?? '')) . ' #' . ((string)($event['target_id'] ?? '')));
        $severityIcons = ['info' => '[i]', 'warn' => '[!]', 'error' => '[x]', 'critical' => '[!!!]'];
        $sev = (string)($event['severity'] ?? 'info');
        $this->severityBadge = ($severityIcons[$sev] ?? '[?]') . ' ' . strtoupper($sev);
        $at = new \DateTimeImmutable((string)($event['at'] ?? 'now'));
        $diff = time() - $at->getTimestamp();
        $this->whenRelative = $diff < 60 ? 'just now' : ((int) floor($diff / 60)) . ' min ago';
        $this->whenAbsolute = $at->format('Y-m-d H:i:s');
        $this->contextPreview = is_array($event['context'] ?? null)
            ? array_slice($event['context'], 0, 3, true)
            : null;
    }
}

final class AuditTimelineView
{
    public function render(array $events): array
    {
        $items = [];
        foreach ($events as $ev) {
            $item = new AuditDisplayItem();
            $item->fromEvent($ev);
            $items[] = $item;
        }
        return $items;
    }
}
