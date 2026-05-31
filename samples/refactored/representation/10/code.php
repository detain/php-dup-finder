<?php
declare(strict_types=1);

namespace App\Ticket;

enum TicketPriority: string { case Low = 'low'; case Normal = 'normal'; case High = 'high'; case Urgent = 'urgent'; }
enum TicketStatus: string { case Open = 'open'; case Pending = 'pending'; case Solved = 'solved'; case Closed = 'closed'; }

final class Ticket
{
    public function __construct(
        public readonly int $id,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $requesterEmail,
        public readonly string $requesterName,
        public readonly TicketPriority $priority,
        public readonly TicketStatus $status,
        public readonly ?int $assigneeId,
        public readonly ?string $internalNotes,
        public readonly \DateTimeImmutable $openedAt,
        public readonly ?\DateTimeImmutable $closedAt,
        public readonly \DateTimeImmutable $lastUpdatedAt,
    ) {
        if ($id <= 0 || $subject === '') {
            throw new \InvalidArgumentException('Need id/subject');
        }
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int)$row['id'],
            (string)$row['subject'],
            (string)$row['body'],
            strtolower((string)$row['requester_email']),
            (string)$row['requester_name'],
            TicketPriority::from((string)$row['priority']),
            TicketStatus::from((string)$row['status']),
            isset($row['assignee_id']) ? (int)$row['assignee_id'] : null,
            isset($row['internal_notes']) ? (string)$row['internal_notes'] : null,
            new \DateTimeImmutable((string)$row['opened_at']),
            !empty($row['closed_at']) ? new \DateTimeImmutable((string)$row['closed_at']) : null,
            new \DateTimeImmutable((string)$row['updated_at']),
        );
    }

    public function reference(): string { return 'TKT-' . str_pad((string)$this->id, 6, '0', STR_PAD_LEFT); }

    public function ageHours(\DateTimeImmutable $now): int
    {
        $end = $this->closedAt ?? $now;
        return (int) round(($end->getTimestamp() - $this->openedAt->getTimestamp()) / 3600);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [TicketStatus::Open, TicketStatus::Pending], true);
    }
}
